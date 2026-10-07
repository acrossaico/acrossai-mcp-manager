<?php
/**
 * Claude Desktop MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a `claude_desktop_config.json` snippet.
 *
 * Target file (macOS): `~/Library/Application Support/Claude/claude_desktop_config.json`
 * Top-level key: `mcpServers`
 */
final class ClaudeDesktopClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'claude-desktop';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Claude Desktop';
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
		return '🍰';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Anthropic Claude Desktop App', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/Library/Application Support/Claude/claude_desktop_config.json';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Verified 2026-10-07 against the official MCP documentation, "Connect to
	 * local MCP servers", which lists both paths verbatim under the Developer
	 * settings step:
	 * {@link https://modelcontextprotocol.io/docs/develop/connect-local-servers}
	 *
	 *   macOS   ~/Library/Application Support/Claude/claude_desktop_config.json
	 *   Windows %APPDATA%\Claude\claude_desktop_config.json
	 *
	 * NO `linux` key, deliberately. The same page states "Claude Desktop is
	 * available for macOS and Windows", and gives no Linux path anywhere —
	 * only unofficial community builds exist. Inventing
	 * `~/.config/Claude/...` would look authoritative and send Linux users to
	 * a file that the app they are running does not read.
	 *
	 * @since 0.3.9
	 * @return array<string, string>
	 */
	public function get_config_files(): array {
		return array(
			'macos'   => '~/Library/Application Support/Claude/claude_desktop_config.json',
			'windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_top_level_key(): string {
		return 'mcpServers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_instructions(): string {
		return __( 'Generate a password → copy the JSON → open the config file path above → paste under the top-level key → restart Claude Desktop.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 10;
	}
}
