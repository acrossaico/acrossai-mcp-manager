<?php
/**
 * Zed editor MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a Zed `settings.json` snippet.
 *
 * Target file: `~/.config/zed/settings.json`
 * Top-level key: `context_servers` (Zed's own naming — differs from the
 * near-universal `mcpServers` used by every other client). Each server
 * entry MUST include `source: 'custom'` + `enabled: true` for Zed to load
 * user-configured MCP servers correctly.
 */
final class ZedClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'zed';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Zed';
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
			'context_servers' => array(
				$this->derive_server_key( $server_url ) => array(
					'source'  => 'custom',
					'enabled' => true,
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
		return __( 'Zed — high-performance code editor', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.config/zed/settings.json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_top_level_key(): string {
		return 'context_servers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_instructions(): string {
		return __( 'Generate a password → copy the JSON → open ~/.config/zed/settings.json → merge under context_servers → restart Zed.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 73;
	}
}
