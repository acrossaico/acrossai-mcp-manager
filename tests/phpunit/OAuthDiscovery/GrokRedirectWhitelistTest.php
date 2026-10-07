<?php
/**
 * Locks Grok's redirect-URI whitelist.
 *
 * Grok has two connector surfaces and only one self-registers:
 * `grok.com/connectors` performs DCR, while the Business / Enterprise surface
 * at `console.x.ai` is admin-only and demands manual OAuth credentials. Until
 * 0.9.13 this profile returned an empty whitelist, which hid the admin
 * "Generate credentials" button and made `POST /oauth/generate-client` return
 * 409 — so the plugin could not serve that second surface at all.
 *
 * These assertions are source-level. `GrokConnectorProfile` extends an
 * abstract that lives behind the mcp-manager host-dependency probe, so
 * instantiating it here would mean stubbing that abstract — which is exactly
 * what `tests/Unit/ClaudeConnectorProfileTest.php` does, under the wrong
 * namespace, and why it no longer passes. Pinning the literal is enough for
 * the invariant that matters: the URI is byte-exact and singular.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use PHPUnit\Framework\TestCase;

final class GrokRedirectWhitelistTest extends TestCase {

	private function grok_source(): string {
		$src = file_get_contents( dirname( __DIR__, 3 ) . '/includes/ConnectorProfiles/GrokConnectorProfile.php' );
		self::assertNotFalse( $src, 'GrokConnectorProfile.php must be readable.' );
		return (string) $src;
	}

	/**
	 * Byte-exact, including the trailing slash.
	 * `AuthorizationController::assert_redirect_uri_or_die()` compares with
	 * `hash_equals`, and the RFC 8252 §7.3 loopback exception does not apply
	 * to a non-loopback host — so a missing slash is a failed connection.
	 */
	public function test_whitelist_is_the_observed_grok_callback(): void {
		self::assertStringContainsString(
			"private const REDIRECT_URIS = array( 'https://grok.com/connectors-oauth-exchange-code/' );",
			$this->grok_source(),
			'The URI is byte-matched at authorize time; the trailing slash is significant.'
		);
	}

	public function test_whitelist_accessor_returns_the_constant(): void {
		self::assertStringContainsString(
			"public function get_redirect_uri_whitelist(): array {\n\t\treturn self::REDIRECT_URIS;\n\t}",
			$this->grok_source(),
			'An empty whitelist re-disables admin credential generation for Grok.'
		);
	}

	/**
	 * No wildcards, no port flexibility, one entry. Mirrors the FR-002
	 * language applied to Claude.
	 */
	public function test_whitelist_has_no_wildcard_and_a_single_entry(): void {
		$src = $this->grok_source();

		preg_match( '/private const REDIRECT_URIS = array\((.*?)\);/s', $src, $m );
		self::assertNotEmpty( $m, 'REDIRECT_URIS const must exist.' );

		self::assertSame( 1, substr_count( $m[1], 'https://' ), 'Exactly one redirect URI.' );
		self::assertStringNotContainsString( '*', $m[1], 'No wildcards.' );
	}

	/**
	 * The setup instructions must carry the endpoint VALUES, not just field
	 * names — an operator on console.x.ai has no other way to discover them,
	 * and omitting them is what left those accounts with unusable credentials.
	 */
	public function test_setup_instructions_render_the_oauth_endpoints(): void {
		$src = $this->grok_source();

		self::assertStringContainsString( "DiscoveryController::issuer()", $src );
		self::assertStringContainsString( "\$issuer . '/authorize'", $src );
		self::assertStringContainsString( "\$issuer . '/token'", $src );
		self::assertStringContainsString( 'client_secret_post', $src, 'A generated pair carries a secret.' );
	}

	/**
	 * The generated credentials must render next to the button that produced
	 * them, not after the how-to panel. Every profile overrides
	 * `render_card_body()` as `parent::render_card_body(); <how-to panel>`, so
	 * the result target has to be emitted inside the parent — emitting it from
	 * `render_default_card()` after the override put a once-visible client
	 * secret below a full page of setup steps.
	 */
	public function test_result_target_renders_inside_card_body_not_after_it(): void {
		$src = file_get_contents( dirname( __DIR__, 3 ) . '/includes/Connectors/AbstractConnectorProfile.php' );
		self::assertNotFalse( $src );
		$src = (string) $src;

		$body_pos = strpos( $src, 'protected function render_card_body( array $server ): void {' );
		$target   = strpos( $src, '$this->render_result_target( $server );', (int) $body_pos );
		$body_end = strpos( $src, 'protected function render_result_target', (int) $body_pos );

		self::assertIsInt( $body_pos );
		self::assertIsInt( $target );
		self::assertLessThan(
			(int) $body_end,
			(int) $target,
			'render_card_body() must emit the result target itself.'
		);

		self::assertSame(
			1,
			substr_count( $src, '$this->render_result_target( $server );' ),
			'Exactly one emission point, or the JS querySelector picks the wrong container.'
		);
	}

	/**
	 * The DCR panel and the generated-credentials panel must not contradict
	 * each other now that both paths are live.
	 */
	public function test_dcr_panel_scopes_its_leave_blank_advice_to_grok_com(): void {
		self::assertStringContainsString(
			'console.x.ai admin surface below is different',
			$this->grok_source(),
			'"Leave Advanced OAuth fields empty" is true for grok.com only.'
		);
	}
}
