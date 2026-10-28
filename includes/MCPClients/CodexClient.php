<?php
/**
 * OpenAI Codex CLI MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a Codex CLI `config.toml` snippet.
 *
 * Target file: `~/.codex/config.toml`
 * Top-level table: `mcp_servers`
 *
 * THE ONLY CLIENT THAT EMITS A STRING. Every other built-in returns an array
 * that the renderers JSON-encode. Codex's configuration file is TOML, so an
 * array would be encoded as JSON and handed to the operator under a `.toml`
 * path — which is exactly what this class did until F099.
 *
 * The mismatch was survivable while a human read the config and adapted. It
 * stopped being survivable when F099 began generating a prompt instructing an
 * AI client to merge the block into `config.toml`: an assistant told to merge
 * JSON into a TOML file either fails or writes a broken file, and the operator
 * has no way to tell which.
 *
 * `get_config_snippet()` has always been documented `string|array` for this
 * case. This is the first in-tree use of the string branch.
 */
final class CodexClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'codex';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Codex';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Returns TOML, not an array. See the class docblock for why.
	 *
	 * The environment goes in its own `[mcp_servers.<key>.env]` sub-table
	 * rather than an inline table: there are four or five variables, one of
	 * them a long URL, and an inline table would be a single unreadable line
	 * the operator cannot check by eye.
	 *
	 * @param string $server_url Already-sanitised server URL.
	 * @param string $auth_token Already-issued Application Password (may be empty).
	 *
	 * @return string TOML.
	 */
	public function get_config_snippet( string $server_url, string $auth_token ): string {
		$key = self::toml_key( $this->derive_server_key( $server_url ) );

		$lines = array(
			sprintf( '[mcp_servers.%s]', $key ),
			'command = "npx"',
			'args = ["-y", "@automattic/mcp-wordpress-remote@latest"]',
			'',
			sprintf( '[mcp_servers.%s.env]', $key ),
		);

		foreach ( $this->build_env( $server_url, $auth_token ) as $name => $value ) {
			$lines[] = sprintf( '%s = "%s"', $name, self::toml_escape( (string) $value ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render a server key as a TOML table key.
	 *
	 * TOML bare keys permit letters, digits, underscores and dashes. Server
	 * keys derive from a site slug and a route segment and are normally bare-
	 * safe, but a key that is not gets quoted rather than producing a file that
	 * fails to parse.
	 *
	 * @param string $key Raw server key.
	 * @return string Bare or quoted TOML key.
	 */
	private static function toml_key( string $key ): string {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]+\z/', $key )
			? $key
			: '"' . self::toml_escape( $key ) . '"';
	}

	/**
	 * Escape a TOML basic string.
	 *
	 * Backslash first — escaping it after the quote would double-escape the
	 * backslashes this function itself introduces.
	 *
	 * @param string $value Raw value.
	 * @return string Escaped for use inside double quotes.
	 */
	private static function toml_escape( string $value ): string {
		return str_replace(
			array( '\\', '"', "\n", "\r", "\t" ),
			array( '\\\\', '\\"', '\\n', '\\r', '\\t' ),
			$value
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon(): string {
		return '🐙';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'OpenAI Codex CLI', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_config_file(): string {
		return '~/.codex/config.toml';
	}

	/**
	 * {@inheritDoc}
	 *
	 * TOML rather than JSON, but the same home-relative shape. Home-relative on every platform: the client resolves `~` itself, but `~`
	 * is unix shorthand a Windows user cannot paste into Explorer, so the
	 * Windows row spells out `%USERPROFILE%` with backslashes (issue #159).
	 * Linux uses the same location as macOS.
	 *
	 * @since 0.3.9
	 * @return array<string, string>
	 */
	public function get_config_files(): array {
		return array(
			'macos'   => '~/.codex/config.toml',
			'windows' => '%USERPROFILE%\\.codex\\config.toml',
			'linux'   => '~/.codex/config.toml',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_top_level_key(): string {
		return 'mcp_servers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_instructions(): string {
		return __( 'Generate a password → copy the TOML snippet → open ~/.codex/config.toml → paste both [mcp_servers] tables → restart Codex CLI.', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 50;
	}
}
