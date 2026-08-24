<?php
/**
 * Cursor IDE MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a Cursor `mcp.json` snippet.
 *
 * Target file: `~/.cursor/mcp.json`
 * Top-level key: `mcpServers`
 */
final class CursorClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'cursor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Cursor';
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
		return '⚡';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Cursor AI Code Editor', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.cursor/mcp.json';
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
		return __( 'Generate a password → copy the JSON → open ~/.cursor/mcp.json → paste under mcpServers → reload Cursor.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * F078 (2026-08-24) — override the abstract default. Cursor does not
	 * hot-reload `mcp.json`; the lightest documented action is a Reload
	 * Window or the Settings → MCP toggle. Source: https://cursor.com/docs/mcp
	 */
	public function get_restart_step_text(): string {
		return __( 'Reload Cursor (Cmd/Ctrl + Shift + P → Reload Window) or toggle the server in Settings → MCP to load the new MCP server.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 60;
	}
}
