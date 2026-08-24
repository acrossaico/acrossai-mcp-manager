<?php
/**
 * VS Code MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a VS Code `.vscode/mcp.json` snippet.
 *
 * Target file: `.vscode/mcp.json` (workspace) or
 * `~/Library/Application Support/Code/User/mcp.json` (user)
 * Top-level shape: `{ "servers": { <key>: { ... } } }` per the VS Code
 * MCP extension's configuration spec.
 */
final class VSCodeClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'vscode';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'VS Code';
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
			'servers' => array(
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
		return '▤';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Visual Studio Code', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.vscode/mcp.json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_top_level_key(): string {
		return 'servers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_instructions(): string {
		return __( 'Generate a password → copy the JSON → open .vscode/mcp.json in your workspace (or user-level ~/.vscode/mcp.json) → paste under servers → reload VS Code.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}
}
