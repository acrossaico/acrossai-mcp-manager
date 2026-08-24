<?php
/**
 * Antigravity IDE MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces an Antigravity `mcp_config.json` snippet.
 *
 * Target file: `~/.gemini/config/mcp_config.json` (global on macOS/Linux)
 * or `.agents/mcp_config.json` (workspace-scoped). Same snippet covers the
 * Antigravity IDE and the Antigravity CLI — both share the config location.
 * Top-level key: `mcpServers`
 */
final class AntigravityClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'antigravity';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Antigravity';
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
		return '🛰️';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Antigravity — agent-first coding environment (IDE + CLI)', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.gemini/config/mcp_config.json';
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
		return __( 'Generate a password → copy the JSON → open ~/.gemini/config/mcp_config.json (global) or .agents/mcp_config.json (workspace) → paste under mcpServers → restart Antigravity.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 79;
	}
}
