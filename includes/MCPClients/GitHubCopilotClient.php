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
	 *
	 * F078 (2026-08-24) — corrected macOS user-level path (same fix as
	 * VSCodeClient). Source:
	 * https://code.visualstudio.com/docs/copilot/customization/mcp-servers
	 */
	public function get_config_file(): string {
		return '~/Library/Application Support/Code/User/mcp.json';
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
		return __( 'Generate a password → copy the JSON → open the user-level ~/Library/Application Support/Code/User/mcp.json (or Cmd/Ctrl + Shift + P → "MCP: Open User Configuration") → paste under servers. In Copilot Chat, switch to Agent mode to see the new tools.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * F078 — Copilot picks up new MCP servers automatically once VS Code
	 * (re)starts them; the operator just needs to be in Agent mode.
	 */
	public function get_restart_step_text(): string {
		return __( 'Ensure Copilot Chat is in Agent mode; VS Code auto-starts the new MCP server once the config is saved (reload the window if it does not appear).', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 40;
	}
}
