<?php
/**
 * Amazon Q Developer MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces an Amazon Q Developer `mcp.json` snippet.
 *
 * Target file: `~/.aws/amazonq/mcp.json` (global) or `.amazonq/mcp.json`
 * (project-scoped).
 * Top-level key: `mcpServers`
 */
final class AmazonQClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'amazon-q';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Amazon Q Developer';
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
		return '☁️';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Amazon Q Developer — AWS coding assistant', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.aws/amazonq/mcp.json';
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
		return __( 'Generate a password → copy the JSON → open ~/.aws/amazonq/mcp.json (global) or .amazonq/mcp.json (project) → paste under mcpServers → restart Amazon Q.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 77;
	}
}
