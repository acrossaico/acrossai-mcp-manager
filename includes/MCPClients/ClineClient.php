<?php
/**
 * Cline (VS Code extension) MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a Cline MCP settings snippet.
 *
 * Target file: `cline_mcp_settings.json` (VS Code extension storage —
 * exact OS path varies; easiest access is via the Cline sidebar
 * "Configure MCP Servers" command which opens the correct file).
 * Top-level key: `mcpServers`
 */
final class ClineClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'cline';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Cline';
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
		return '🤖';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Cline — autonomous coding agent for VS Code', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return 'cline_mcp_settings.json';
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
		return __( 'Generate a password → copy the JSON → open the Cline sidebar in VS Code → click MCP Servers → Configure MCP Servers → paste under mcpServers.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 74;
	}
}
