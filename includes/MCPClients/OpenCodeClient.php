<?php
/**
 * OpenCode CLI MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces an OpenCode `opencode.json` snippet.
 *
 * Target file: `~/.config/opencode/opencode.json` (global) or
 * `opencode.json` (project-scoped).
 *
 * NOTE: OpenCode uses a DIFFERENT top-level shape than the near-universal
 * `mcpServers` — its MCP section lives under a top-level `mcp` key, and
 * each server declares `type: 'local'` + `command: [array, of, tokens]`
 * instead of the `command: 'npx' + args: [...]` split most clients use.
 */
final class OpenCodeClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'opencode';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'OpenCode';
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
				$this->derive_server_key( $server_url ) => array(
					'type'        => 'local',
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
		return '📟';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'OpenCode — open-source terminal-first AI coding agent', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.config/opencode/opencode.json';
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
		return __( 'Generate a password → copy the JSON → open ~/.config/opencode/opencode.json (global) or opencode.json (project) → merge under mcp → restart OpenCode.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 78;
	}
}
