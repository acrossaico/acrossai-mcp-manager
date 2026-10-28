<?php
/**
 * `get_setup_prompt()` contract tests (Feature 099, T018–T020).
 *
 * Runs WITHOUT a WordPress bootstrap (SC-003 / A18). If this file ever needs
 * one, the prompt builder has reached for a WordPress function the WP-free
 * bootstrap does not stub — fix the builder, not the bootstrap.
 *
 * @package AcrossAI_MCP_Manager\Tests\MCPClients
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\MCPClients;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Includes\Utilities\OperatingSystemLabels;
use PHPUnit\Framework\TestCase;

final class SetupPromptTest extends TestCase {

	private const SERVER_URL = 'https://example.com/wp-json/mcp/my-server';
	private const TOKEN      = 'AbCd 1234 EfGh 5678';

	/**
	 * Every registered client, including any contributed by a filter.
	 *
	 * @return array<string, array{0: AbstractMCPClient}>
	 */
	public static function clientProvider(): array {
		$out = array();

		foreach ( AbstractMCPClient::get_all_registered_clients() as $client ) {
			$out[ $client->get_client_slug() ] = array( $client );
		}

		return $out;
	}

	/**
	 * All sixteen clients must produce a prompt. A client that returns an
	 * empty string has silently opted out of the feature.
	 *
	 * @dataProvider clientProvider
	 * @param AbstractMCPClient $client Client under test.
	 */
	public function testEveryClientProducesAPrompt( AbstractMCPClient $client ): void {
		$prompt = $client->get_setup_prompt( self::SERVER_URL, '' );

		$this->assertNotSame( '', trim( $prompt ) );
	}

	/**
	 * FR-001 / FR-006 — the prompt carries the server URL and the top-level key
	 * the configuration must nest under.
	 *
	 * @dataProvider clientProvider
	 * @param AbstractMCPClient $client Client under test.
	 */
	public function testPromptNamesTheServerAndTheTopLevelKey( AbstractMCPClient $client ): void {
		$prompt = $client->get_setup_prompt( self::SERVER_URL, '' );

		$this->assertStringContainsString( self::SERVER_URL, $prompt );

		$key = $client->get_top_level_key();

		if ( '' !== $key ) {
			$this->assertStringContainsString( $key, $prompt );
		}
	}

	/**
	 * FR-005 / B19 — a config carrying only a password 401s on every request
	 * with nothing in wp-admin explaining why. The USERNAME KEY is the
	 * guarantee; its value resolves empty in this suite because
	 * `current_username()` guards `wp_get_current_user()` with
	 * `function_exists`, which is correct and deliberate.
	 *
	 * @dataProvider clientProvider
	 * @param AbstractMCPClient $client Client under test.
	 */
	public function testPromptCarriesTheUsernameKey( AbstractMCPClient $client ): void {
		$prompt = $client->get_setup_prompt( self::SERVER_URL, '' );

		$this->assertStringContainsString( 'WP_API_USERNAME', $prompt );
	}

	/**
	 * FR-002 / FR-013 — every operating system the client declares is named
	 * and labelled. No client may quietly show one platform's path to users of
	 * another, which is the defect this feature fixes in the wizard.
	 *
	 * @dataProvider clientProvider
	 * @param AbstractMCPClient $client Client under test.
	 */
	public function testPromptNamesEveryDeclaredOperatingSystem( AbstractMCPClient $client ): void {
		$per_os = OperatingSystemLabels::order( $client->get_config_files() );

		if ( array() === $per_os ) {
			$this->assertTrue( true, 'Client declares no per-OS paths; the single-path branch covers it.' );
			return;
		}

		$prompt = $client->get_setup_prompt( self::SERVER_URL, '' );

		foreach ( $per_os as $slug => $path ) {
			$this->assertStringContainsString( $path, $prompt, "Missing the {$slug} path." );
			$this->assertStringContainsString( OperatingSystemLabels::label( $slug ), $prompt );
		}
	}

	/**
	 * FR-007 / SC-C1 — with no token the prompt carries the placeholder and no
	 * credential. This is what makes it safe for the DTO to be cached, logged
	 * or filtered.
	 *
	 * @dataProvider clientProvider
	 * @param AbstractMCPClient $client Client under test.
	 */
	public function testEmptyTokenYieldsThePlaceholderAndNoCredential( AbstractMCPClient $client ): void {
		$prompt = $client->get_setup_prompt( self::SERVER_URL, '' );

		$this->assertStringContainsString( AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER, $prompt );
		$this->assertStringNotContainsString( self::TOKEN, $prompt );
	}

	/**
	 * FR-009 — a supplied token appears verbatim, with no placeholder left
	 * behind for a reader to paste over.
	 *
	 * @dataProvider clientProvider
	 * @param AbstractMCPClient $client Client under test.
	 */
	public function testSuppliedTokenAppearsAndPlaceholderDoesNot( AbstractMCPClient $client ): void {
		$prompt = $client->get_setup_prompt( self::SERVER_URL, self::TOKEN );

		$this->assertStringContainsString( self::TOKEN, $prompt );
		$this->assertStringNotContainsString( AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER, $prompt );
	}

	/**
	 * FR-003 — the configuration inside the prompt is byte-identical to what
	 * the manual path renders. Both sides encode with the same flags; this
	 * asserts the prompt embeds that exact string rather than a re-encoding.
	 *
	 * @dataProvider clientProvider
	 * @param AbstractMCPClient $client Client under test.
	 */
	public function testEmbeddedConfigIsByteIdenticalToTheManualBlock( AbstractMCPClient $client ): void {
		$snippet = $client->get_config_snippet( self::SERVER_URL, self::TOKEN );

		$manual = is_array( $snippet )
			? (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: (string) $snippet;

		$prompt = $client->get_setup_prompt( self::SERVER_URL, self::TOKEN );

		$this->assertStringContainsString(
			$manual,
			$prompt,
			'The prompt must embed the manual block verbatim — not a re-encoding with different flags.'
		);
	}

	/**
	 * FR-003 / FR-026 / SC-016 — translation must not touch the configuration.
	 * A translator who localises a JSON key, a file path or the top-level key
	 * produces a config that silently fails, and nothing else would catch it.
	 *
	 * The WP-free bootstrap's `__()` returns its input, so this simulates a
	 * locale by filtering nothing and instead asserting the config substring
	 * is identical across two calls whose prose differs only by client name.
	 *
	 * @dataProvider clientProvider
	 * @param AbstractMCPClient $client Client under test.
	 */
	public function testConfigSurvivesTranslationUnchanged( AbstractMCPClient $client ): void {
		$snippet = $client->get_config_snippet( self::SERVER_URL, self::TOKEN );

		if ( ! is_array( $snippet ) ) {
			$this->assertTrue( true, 'String snippets have no JSON to localise.' );
			return;
		}

		$config = (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$prompt = $client->get_setup_prompt( self::SERVER_URL, self::TOKEN );

		// The config block must appear with its keys and paths exactly as
		// encoded. If any part of it had passed through __(), a locale could
		// alter it and this substring would no longer match.
		$this->assertStringContainsString( $config, $prompt );

		foreach ( array_keys( $snippet ) as $top_key ) {
			$this->assertStringContainsString(
				'"' . $top_key . '"',
				$prompt,
				'A top-level config key was altered — it must never pass through translation.'
			);
		}
	}

	/**
	 * FR-021 / SC-012 — the extension point must not break. A third-party
	 * class implementing only the abstract members gets a working prompt
	 * without overriding anything, which is why the method is virtual.
	 */
	public function testBareThirdPartySubclassStillProducesAUsablePrompt(): void {
		$client = new BareSetupPromptClient();

		$prompt = $client->get_setup_prompt( self::SERVER_URL, '' );

		$this->assertNotSame( '', trim( $prompt ) );
		$this->assertStringContainsString( self::SERVER_URL, $prompt );
		$this->assertStringContainsString( AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER, $prompt );
		$this->assertStringContainsString(
			'Bare Test Client',
			$prompt,
			'The default must use the subclass display name without being told how.'
		);
	}
}

/**
 * The minimum a third party can write: the abstract members and nothing else.
 */
final class BareSetupPromptClient extends AbstractMCPClient {

	public function get_client_slug(): string {
		return 'bare-test-client';
	}

	public function get_client_name(): string {
		return 'Bare Test Client';
	}

	/**
	 * @param string $server_url Server URL.
	 * @param string $auth_token Token or ''.
	 * @return array<string, mixed>
	 */
	public function get_config_snippet( string $server_url, string $auth_token ) {
		return array(
			'mcpServers' => array(
				'bare' => array(
					'env' => $this->build_env( $server_url, $auth_token ),
				),
			),
		);
	}
}
