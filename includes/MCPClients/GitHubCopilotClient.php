<?php
/**
 * GitHub Copilot MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a GitHub Copilot MCP snippet shaped as the Copilot preview
 * spec expects: a `mcp.servers` namespaced envelope inside the user's
 * `.vscode/mcp.json` (Copilot reuses VS Code's MCP slot but namespaced
 * differently to avoid colliding with the VS Code MCP extension).
 */
final class GitHubCopilotClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'github-copilot';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'GitHub Copilot';
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
			'mcp' => array(
				'servers' => array(
					$this->derive_server_key( $server_url ) => array(
						'command' => 'npx',
						'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
						'env'     => $this->build_env( $server_url, $auth_token ),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon(): string {
		return '🐱';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'GitHub Copilot in VS Code (user-level MCP config)', 'acrossai-mcp-manager' );
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
		return __( 'Generate a password → copy the JSON → open the user-level ~/.vscode/mcp.json → paste under servers → restart VS Code + GitHub Copilot extension.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_restart_step_text(): string {
		return __( 'Restart VS Code and reactivate the GitHub Copilot extension to load the new MCP server.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 40;
	}
}
