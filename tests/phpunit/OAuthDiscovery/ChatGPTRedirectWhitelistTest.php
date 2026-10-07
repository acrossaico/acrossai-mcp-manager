<?php
/**
 * Locks ChatGPT's redirect-URI whitelist AND the RFC 9207 conditions that
 * make it the correct one.
 *
 * ChatGPT picks its callback shape from the authorization server, not from
 * the connector: a server that fails OpenAI's issuer-identification
 * requirement gets the per-connection form
 * `https://chatgpt.com/connector/oauth/{callback_id}`, which cannot be
 * whitelisted in advance; a server that meets it gets the stable
 * `https://chatgpt.com/connector_platform_oauth_redirect`. Until 0.9.16 this
 * profile returned an empty whitelist on the reading that only the derived
 * form existed, which hid the admin "Generate credentials" button and made
 * `POST /oauth/generate-client` answer 409 — ChatGPT was the one connector
 * that could not be credentialed manually.
 *
 * So the whitelist assertion alone would be a half-test. The three RFC 9207
 * assertions below are the other half: if any of them regresses, ChatGPT
 * switches to the derived callback and admin-generated credentials start
 * failing at `/authorize` with "Invalid redirect_uri for this client." The
 * whitelist would still look right while being wrong.
 *
 * These assertions are source-level for the same reason
 * {@see GrokRedirectWhitelistTest} is: the profile extends an abstract that
 * lives behind the mcp-manager host-dependency probe, so instantiating it
 * here would mean stubbing that abstract.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use PHPUnit\Framework\TestCase;

final class ChatGPTRedirectWhitelistTest extends TestCase {

	private function source( string $relative_path ): string {
		$src = file_get_contents( dirname( __DIR__, 3 ) . '/' . $relative_path );
		self::assertNotFalse( $src, $relative_path . ' must be readable.' );
		return (string) $src;
	}

	private function chatgpt_source(): string {
		return $this->source( 'includes/ConnectorProfiles/ChatGPTConnectorProfile.php' );
	}

	/**
	 * Byte-exact. `AuthorizationController::assert_redirect_uri_or_die()`
	 * compares with `hash_equals` against the URI snapshotted onto the client
	 * row, and the RFC 8252 §7.3 loopback exception does not apply to a
	 * non-loopback host — so a stray trailing slash is a failed connection.
	 */
	public function test_whitelist_is_the_stable_chatgpt_callback(): void {
		self::assertStringContainsString(
			"private const REDIRECT_URIS = array( 'https://chatgpt.com/connector_platform_oauth_redirect' );",
			$this->chatgpt_source(),
			'The URI is byte-matched at authorize time; no trailing slash, no path variation.'
		);
	}

	public function test_whitelist_accessor_returns_the_constant(): void {
		self::assertStringContainsString(
			"public function get_redirect_uri_whitelist(): array {\n\t\treturn self::REDIRECT_URIS;\n\t}",
			$this->chatgpt_source(),
			'An empty whitelist re-hides the Generate-credentials button and restores the 409.'
		);
	}

	/**
	 * No wildcards, no per-connector segment, one entry.
	 */
	public function test_whitelist_has_no_wildcard_and_a_single_entry(): void {
		preg_match( '/private const REDIRECT_URIS = array\((.*?)\);/s', $this->chatgpt_source(), $m );
		self::assertNotEmpty( $m, 'REDIRECT_URIS const must exist.' );

		self::assertSame( 1, substr_count( $m[1], 'https://' ), 'Exactly one redirect URI.' );
		self::assertStringNotContainsString( '*', $m[1], 'No wildcards.' );
		self::assertStringNotContainsString( '{', $m[1], 'The callback-id form is not whitelistable.' );
	}

	/**
	 * RFC 9207 condition 1 of 3 — the metadata flag. Without it ChatGPT has
	 * no way to know the `iss` it will receive is trustworthy, and falls back
	 * to the derived callback.
	 */
	public function test_authorization_server_metadata_advertises_iss_support(): void {
		self::assertMatchesRegularExpression(
			"/'authorization_response_iss_parameter_supported'\s*=>\s*true/",
			$this->source( 'includes/OAuth/DiscoveryController.php' ),
			'OpenAI reads this flag to decide which redirect URI to send.'
		);
	}

	/**
	 * RFC 9207 conditions 2 and 3 — `iss` on EVERY authorization response,
	 * success and error alike. The error path is the one easy to drop, and
	 * dropping it is non-obvious because the happy path keeps working.
	 */
	public function test_every_authorization_response_carries_iss(): void {
		$src = $this->source( 'includes/OAuth/AuthorizationController.php' );

		self::assertSame(
			2,
			substr_count( $src, "'iss'" ),
			'Exactly two emission points: the approval redirect and redirect_error().'
		);

		$approval = strpos( $src, "'code'  => rawurlencode( \$issued['raw'] )," );
		self::assertIsInt( $approval, 'Approval redirect must build the callback with the issued code.' );
		self::assertIsInt(
			strpos( $src, "'iss'", (int) $approval ),
			'The approval redirect must carry iss.'
		);

		$error = strpos( $src, 'private static function redirect_error(' );
		self::assertIsInt( $error, 'redirect_error() must exist.' );
		self::assertIsInt(
			strpos( $src, "'iss'", (int) $error ),
			'Error redirects must carry iss too — RFC 9207 makes no exception for them.'
		);
	}

	/**
	 * The `iss` value must be byte-equal to the metadata `issuer`: OpenAI
	 * compares them as strings and normalizes nothing. Both sides reading the
	 * same accessor is what makes that hold by construction — a literal on
	 * either side is the regression to catch.
	 */
	public function test_iss_and_issuer_come_from_the_same_accessor(): void {
		self::assertSame(
			2,
			substr_count( $this->source( 'includes/OAuth/AuthorizationController.php' ), 'DiscoveryController::issuer()' ),
			'Both iss emissions must read DiscoveryController::issuer(), never a literal.'
		);
	}

	/**
	 * The generated pair is useless if the steps still name a dialog OpenAI
	 * retired — and the how-to panel must stop telling operators the fields
	 * are to be left blank, full stop, now that filling them is supported.
	 */
	public function test_instructions_describe_the_current_surface_and_both_paths(): void {
		$src = $this->chatgpt_source();

		self::assertStringNotContainsString(
			'Settings → Connectors → Add custom connector',
			$src,
			'That dialog was retired when Connectors became Plugins in mid-2026.'
		);
		self::assertStringContainsString(
			'Settings → Plugins',
			$src,
			'The credential steps must name the surface that actually has the OAuth fields.'
		);
		self::assertStringContainsString(
			'or click "Generate credentials" above',
			$src,
			'The DCR panel must offer the manual path now that it exists.'
		);
	}
}
