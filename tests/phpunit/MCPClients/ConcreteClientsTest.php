<?php
/**
 * @package AcrossAI_MCP_Manager\Tests\MCPClients
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\MCPClients;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeCodeClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeDesktopClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CodexClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CursorClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CustomClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\GeminiClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\GitHubCopilotClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\VSCodeClient;
use PHPUnit\Framework\TestCase;

/**
 * Golden-fixture tests for the 8 concrete MCP clients.
 *
 * Fixture files live in tests/phpunit/MCPClients/fixtures/
 *   <slug>-with-token.{json,txt}   — non-empty token golden output
 *   <slug>-empty-token.{json,txt}  — empty-token golden output (verifies
 *                                     safe_token() placeholder substitution)
 *
 * Per SC-003 this suite runs WITHOUT WordPress bootstrap.
 */
final class ConcreteClientsTest extends TestCase {

	private const TEST_URL   = 'https://example.com/wp-json/mcp/test-server';
	private const TEST_TOKEN = 'abcd EFGH ijkl MNOP';

	/**
	 * @return array<string, array{0: AbstractMCPClient, 1: string, 2: string}>
	 */
	public static function clientFixtureProvider(): array {
		return array(
			'ClaudeCode'    => array( new ClaudeCodeClient(),    'claude-code',    'Claude Code' ),
			'ClaudeDesktop' => array( new ClaudeDesktopClient(), 'claude-desktop', 'Claude Desktop' ),
			'Codex'         => array( new CodexClient(),         'codex',          'Codex' ),
			'Cursor'        => array( new CursorClient(),        'cursor',         'Cursor' ),
			'Custom'        => array( new CustomClient(),        'custom',         'Custom Client' ),
			'Gemini'        => array( new GeminiClient(),        'gemini',         'Gemini CLI' ),
			'GitHubCopilot' => array( new GitHubCopilotClient(), 'github-copilot', 'GitHub Copilot' ),
			'VSCode'        => array( new VSCodeClient(),        'vscode',         'VS Code' ),
		);
	}

	/**
	 * @dataProvider clientFixtureProvider
	 */
	public function testGetClientSlugMatchesSpec(
		AbstractMCPClient $client,
		string $expected_slug,
		string $expected_name
	): void {
		$this->assertSame( $expected_slug, $client->get_client_slug() );
	}

	/**
	 * @dataProvider clientFixtureProvider
	 */
	public function testGetClientNameMatchesSpec(
		AbstractMCPClient $client,
		string $expected_slug,
		string $expected_name
	): void {
		$this->assertSame( $expected_name, $client->get_client_name() );
	}

	/**
	 * @dataProvider clientFixtureProvider
	 */
	public function testGetConfigSnippetWithToken(
		AbstractMCPClient $client,
		string $expected_slug,
		string $expected_name
	): void {
		$snippet = $client->get_config_snippet( self::TEST_URL, self::TEST_TOKEN );
		$this->assertSnippetMatchesFixture(
			$snippet,
			$expected_slug . '-with-token'
		);
	}

	/**
	 * @dataProvider clientFixtureProvider
	 */
	public function testGetConfigSnippetEmptyToken(
		AbstractMCPClient $client,
		string $expected_slug,
		string $expected_name
	): void {
		$snippet = $client->get_config_snippet( self::TEST_URL, '' );
		$this->assertSnippetMatchesFixture(
			$snippet,
			$expected_slug . '-empty-token'
		);
	}

	// ─── Cross-client invariants ────────────────────────────────────────────

	/**
	 * @dataProvider clientFixtureProvider
	 */
	public function testEmptyTokenRendersPlaceholder(
		AbstractMCPClient $client,
		string $expected_slug,
		string $expected_name
	): void {
		$snippet = $client->get_config_snippet( self::TEST_URL, '' );
		$serialised = is_array( $snippet ) ? (string) json_encode( $snippet, JSON_UNESCAPED_SLASHES ) : $snippet;
		$this->assertStringContainsString(
			'(paste generated password here)',
			$serialised,
			'Empty token MUST render as the placeholder string (spec FR-006 + Q2).'
		);
	}

	/**
	 * @dataProvider clientFixtureProvider
	 */
	public function testWithTokenEmbedsLiteralToken(
		AbstractMCPClient $client,
		string $expected_slug,
		string $expected_name
	): void {
		$snippet = $client->get_config_snippet( self::TEST_URL, self::TEST_TOKEN );
		$serialised = is_array( $snippet ) ? (string) json_encode( $snippet, JSON_UNESCAPED_SLASHES ) : $snippet;
		$this->assertStringContainsString(
			self::TEST_TOKEN,
			$serialised,
			'Non-empty token MUST be embedded verbatim (spec FR-006).'
		);
	}

	/**
	 * @dataProvider clientFixtureProvider
	 */
	public function testWithTokenEmbedsServerUrl(
		AbstractMCPClient $client,
		string $expected_slug,
		string $expected_name
	): void {
		$snippet = $client->get_config_snippet( self::TEST_URL, self::TEST_TOKEN );
		$serialised = is_array( $snippet ) ? (string) json_encode( $snippet, JSON_UNESCAPED_SLASHES ) : $snippet;
		$this->assertStringContainsString(
			self::TEST_URL,
			$serialised,
			'Snippet MUST embed the caller-supplied server URL (spec FR-006).'
		);
	}

	// ─── Fixture comparison helper ──────────────────────────────────────────

	private function assertSnippetMatchesFixture( $snippet, string $fixture_basename ): void {
		$fixtures_dir = __DIR__ . '/fixtures';
		$json_path    = "{$fixtures_dir}/{$fixture_basename}.json";
		$txt_path     = "{$fixtures_dir}/{$fixture_basename}.txt";

		if ( is_array( $snippet ) ) {
			$this->assertFileExists( $json_path, "Missing JSON fixture: {$fixture_basename}.json" );
			$expected = trim( (string) file_get_contents( $json_path ) );
			$actual   = trim( (string) json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			$this->assertSame( $expected, $actual, "Snippet does not match fixture {$fixture_basename}.json" );
		} else {
			$this->assertFileExists( $txt_path, "Missing TXT fixture: {$fixture_basename}.txt" );
			$expected = trim( (string) file_get_contents( $txt_path ) );
			$actual   = trim( (string) $snippet );
			$this->assertSame( $expected, $actual, "Snippet does not match fixture {$fixture_basename}.txt" );
		}
	}
}
