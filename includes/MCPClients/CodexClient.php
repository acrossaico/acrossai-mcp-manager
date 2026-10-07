<?php
/**
 * OpenAI Codex CLI MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a Codex CLI `config.json` snippet.
 *
 * Target file: `~/.codex/config.json`
 * Top-level key: `mcpServers`
 */
final class CodexClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'codex';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Codex';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $server_url Already-sanitised server URL.
	 * @param string $auth_token Already-issued Application Password (may be empty).
	 *
	 * @return array<string, mixed>
	 */
	public function get_config_snippet( string $server_url, string $auth_token ): array {
		return array(
			'mcpServers' => array(
				$this->derive_server_key( $server_url ) => array(
					'command' => 'npx',
					'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
					'env'     => $this->build_env( $server_url, $auth_token ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon(): string {
		return '🐙';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'OpenAI Codex CLI', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.codex/config.toml';
	}

	/**
	 * {@inheritDoc}
	 *
	 * TOML rather than JSON, but the same home-relative shape. Home-relative on every platform: the client resolves `~` itself, but `~`
	 * is unix shorthand a Windows user cannot paste into Explorer, so the
	 * Windows row spells out `%USERPROFILE%` with backslashes (issue #159).
	 * Linux uses the same location as macOS.
	 *
	 * @since 0.3.9
	 * @return array<string, string>
	 */
	public function get_config_files(): array {
		return array(
			'macos'   => '~/.codex/config.toml',
			'windows' => '%USERPROFILE%\\.codex\\config.toml',
			'linux'   => '~/.codex/config.toml',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_top_level_key(): string {
		return 'mcp_servers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_instructions(): string {
		return __( 'Generate a password → copy the TOML snippet → open ~/.codex/config.toml → paste under [mcp_servers] → restart Codex CLI.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 50;
	}
}
