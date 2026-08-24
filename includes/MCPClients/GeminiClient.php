<?php
/**
 * Google Gemini CLI MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a `~/.gemini/settings.json` snippet for Google's Gemini CLI.
 *
 * Target file: `~/.gemini/settings.json`
 * Top-level key: `mcpServers`
 *
 * Uses the standard `@automattic/mcp-wordpress-remote@latest` npx bridge +
 * WP Application Password Basic-auth env vars — identical shape to
 * ClaudeDesktopClient (Gemini CLI accepts the same `mcpServers` config format).
 */
final class GeminiClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Gemini CLI';
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
		return '💎';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Google Gemini CLI', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.gemini/settings.json';
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
		return __( 'Generate a password → copy the JSON → open ~/.gemini/settings.json (create it if missing) → paste under mcpServers → restart Gemini CLI.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 70;
	}
}
