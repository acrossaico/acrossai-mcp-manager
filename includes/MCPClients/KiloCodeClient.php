<?php
/**
 * Kilo Code (VS Code extension) MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a Kilo Code MCP configuration snippet.
 *
 * F078 shape refactor (2026-08-24): Kilo Code v7.0.33+ uses a new format:
 *   - Config file: `.kilo/kilo.jsonc` (project) or `~/.config/kilo/kilo.jsonc` (global)
 *   - Top-level key: `mcp` (not `mcpServers`)
 *   - Server entry uses `command` as a JSON array + `environment` (not `env`)
 * Legacy `.kilocode/mcp.json` + `mcpServers` still works on pre-v7.0.33
 * installs; F078 ships the new format going forward.
 *
 * Source: https://kilo.ai/docs/automate/mcp/using-in-kilo-code
 */
final class KiloCodeClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'kilo-code';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Kilo Code';
	}

	/**
	 * {@inheritDoc}
	 *
	 * F078 — new-shape config: `command` as JSON array + `environment` (not
	 * `env`) under a `mcp` top-level, same family as OpenCodeClient.
	 *
	 * @param string $server_url Already-sanitised server URL.
	 * @param string $auth_token Already-issued Application Password (may be empty).
	 *
	 * @return array<string, mixed>
	 */
	public function get_config_snippet( string $server_url, string $auth_token ): array {
		return array(
			'mcp' => array(
				$this->derive_server_key( $server_url ) => array(
					'command'     => array( 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
					'environment' => $this->build_env( $server_url, $auth_token ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon(): string {
		return '⚙️';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Kilo Code — open-source AI coding agent for VS Code', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.config/kilo/kilo.jsonc';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_top_level_key(): string {
		return 'mcp';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_instructions(): string {
		return __( 'Generate a password → copy the JSONC → open ~/.config/kilo/kilo.jsonc (global) or .kilo/kilo.jsonc (project) — or use the Kilo Code sidebar → MCP Servers → Configure MCP Servers → paste under mcp. On pre-v7.0.33 installs the legacy .kilocode/mcp.json + mcpServers shape still works.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_restart_step_text(): string {
		return __( 'Open the MCP Servers panel in the Kilo Code sidebar; the new server should appear automatically. If it does not, click Restart on the server row.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 76;
	}
}
