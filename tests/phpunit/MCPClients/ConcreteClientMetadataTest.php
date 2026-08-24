<?php
/**
 * F034 — verify the eight built-in MCPClient subclasses migrated
 * CLIENT_META values verbatim into their own metadata method overrides.
 *
 * Data-provider parameterized over all 8 built-ins (spec §Clarifications
 * Q1 priority table + data-model.md §"8 Concrete Client Classes").
 *
 * Per SC-003 this suite runs WITHOUT bootstrapping WordPress.
 *
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConcreteClientMetadataTest extends TestCase {

	/**
	 * @return array<string, array{class-string<AbstractMCPClient>, string, int, string, string, string, string, string}>
	 */
	public static function provideClientMetadata(): array {
		// Row shape: [ FQN, slug, priority, icon, description-en, config_file, top_level_key, instructions-en-first-phrase ]
		return array(
			'claude-desktop' => array(
				ClaudeDesktopClient::class,
				'claude-desktop',
				10,
				'🍰',
				'Anthropic Claude Desktop App',
				'~/Library/Application Support/Claude/claude_desktop_config.json',
				'mcpServers',
				'Generate a password',
			),
			'claude-code' => array(
				ClaudeCodeClient::class,
				'claude-code',
				20,
				'📄',
				'Anthropic Claude Code CLI',
				'~/.claude.json',
				'mcpServers',
				'Generate a password',
			),
			'vscode' => array(
				VSCodeClient::class,
				'vscode',
				30,
				'▤',
				'Visual Studio Code',
				'~/Library/Application Support/Code/User/mcp.json',
				'servers',
				'Generate a password',
			),
			'github-copilot' => array(
				GitHubCopilotClient::class,
				'github-copilot',
				40,
				'🐱',
				'GitHub Copilot in VS Code (user-level MCP config)',
				'~/Library/Application Support/Code/User/mcp.json',
				'servers',
				'Generate a password',
			),
			'codex' => array(
				CodexClient::class,
				'codex',
				50,
				'🐙',
				'OpenAI Codex CLI',
				'~/.codex/config.toml',
				'mcp_servers',
				'Generate a password',
			),
			'cursor' => array(
				CursorClient::class,
				'cursor',
				60,
				'⚡',
				'Cursor AI Code Editor',
				'~/.cursor/mcp.json',
				'mcpServers',
				'Generate a password',
			),
			'gemini' => array(
				GeminiClient::class,
				'gemini',
				70,
				'💎',
				'Google Gemini CLI',
				'~/.gemini/settings.json',
				'mcpServers',
				'Generate a password',
			),
			'custom' => array(
				CustomClient::class,
				'custom',
				80,
				'⚙',
				'Custom MCP Client Implementation',
				'depends on your client',
				'depends on your client',
				'Use the JSON below',
			),
		);
	}

	#[DataProvider('provideClientMetadata')]
	public function testConcreteClientMigratedMetadata(
		string $fqn,
		string $expected_slug,
		int $expected_priority,
		string $expected_icon,
		string $expected_description,
		string $expected_config_file,
		string $expected_top_level_key,
		string $expected_instructions_prefix
	): void {
		$this->assertTrue( class_exists( $fqn ), "Concrete client class {$fqn} MUST exist." );
		$this->assertTrue( is_subclass_of( $fqn, AbstractMCPClient::class ), "{$fqn} MUST extend AbstractMCPClient." );

		/** @var AbstractMCPClient $client */
		$client = new $fqn();

		$this->assertSame( $expected_slug, $client->get_client_slug(), 'Slug preserved from pre-refactor.' );
		$this->assertSame( $expected_priority, $client->get_priority(), 'Priority matches the spec §Clarifications Q1 table.' );
		$this->assertSame( $expected_icon, $client->get_icon(), 'Icon migrated verbatim from CLIENT_META[emoji].' );
		$this->assertSame( $expected_description, $client->get_description(), 'Description migrated verbatim (translated via __).' );
		$this->assertSame( $expected_config_file, $client->get_config_file(), 'Config file path migrated verbatim (untranslated).' );
		$this->assertSame( $expected_top_level_key, $client->get_top_level_key(), 'Top-level key migrated verbatim (untranslated).' );
		$this->assertStringStartsWith( $expected_instructions_prefix, $client->get_instructions(), 'Instructions start with the expected phrase.' );
	}
}
