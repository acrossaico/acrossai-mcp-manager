<?php
/**
 * F093 — WebMCP option storage and server resolution.
 *
 * Three options:
 *
 *   acrossai_mcp_webmcp_enabled        bool,   default 0 — the beta gate
 *   acrossai_mcp_webmcp_allow_execute  bool,   default 0 — may tools RUN?
 *   acrossai_mcp_webmcp_server         string, default '' — the selected slug
 *
 * The first two are deliberately separate. Enabling WebMCP publishes the
 * catalogue; letting an agent act on the site is a second decision, so an
 * operator can confirm the wiring works before granting anything the power
 * to change their content.
 *
 * THE SELECTION IS A SLUG, NEVER AN ID. `DefaultServerSeeder` is explicit
 * that ownership is decided by slug alone and `ProtectedServers` keys off
 * slug; server ids are not stable across a reseed or an environment
 * migration. There is also direct evidence on the dev site: the
 * server-tools table holds fourteen rows for `server_id = 6`, a server that
 * no longer exists. Deleting a server orphans its curated tools, so a reused
 * id would inherit a dead server's tool list.
 *
 * The default resolves LAZILY. An empty option plus the feature enabled
 * means "the recommended server", looked up at read time — no concrete value
 * is written at activation. If that row was deleted we fail closed rather
 * than dangle.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\WebMCP
 * @since      0.4.2
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\WebMCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row;

defined( 'ABSPATH' ) || exit;

/**
 * All static. No hooks — registration lives in the admin page.
 *
 * @since 0.4.2
 */
final class Settings {

	/** Beta gate. Off by default; nothing registers until an operator opts in. */
	public const OPTION_ENABLED = 'acrossai_mcp_webmcp_enabled';

	/** Selected server SLUG. Empty means "resolve the recommended one". */
	public const OPTION_SERVER = 'acrossai_mcp_webmcp_server';

	/**
	 * May an in-browser agent actually RUN a tool? Off by default.
	 *
	 * Two-step consent, and the step that matters. With this off the tools
	 * still register, so an agent can see the catalogue and an operator can
	 * confirm the wiring works — but `/execute` refuses, so nothing can act
	 * on the site.
	 *
	 * The planning doc framed this as "read-only abilities only", which does
	 * not survive contact with the catalogue: a `toolset/*` dispatcher is a
	 * single tool carrying discover, info AND execute behind one `action`
	 * parameter, so there is no read-only subset to publish. Gating the
	 * route is the honest version of the same intent — see the WebMCP admin
	 * page copy, which says exactly this to the operator.
	 */
	public const OPTION_ALLOW_EXECUTE = 'acrossai_mcp_webmcp_allow_execute';

	/**
	 * Is the beta switched on?
	 *
	 * @since 0.4.2
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * May a browser agent run a tool, as opposed to merely seeing it?
	 *
	 * @since 0.4.2
	 * @return bool
	 */
	public static function allows_execute(): bool {
		return (bool) get_option( self::OPTION_ALLOW_EXECUTE, false );
	}

	/**
	 * The configured slug, or the lazily-resolved default.
	 *
	 * Returns the recommended server's slug when nothing is stored. It does
	 * NOT verify the row exists — `selected_row()` is where existence and
	 * enabled-state are decided, so there is exactly one place that can fail
	 * closed.
	 *
	 * @since 0.4.2
	 * @return string
	 */
	public static function selected_slug(): string {
		$stored = trim( (string) get_option( self::OPTION_SERVER, '' ) );

		return '' !== $stored ? $stored : DefaultServerSeeder::ACROSSAI_SLUG;
	}

	/**
	 * The selected server row, or null when it cannot be used.
	 *
	 * Null on every degenerate case the planning doc lists: feature off, no
	 * row for the slug, or the server disabled. A disabled server in
	 * particular must not silently fall back to the default — a dangling
	 * selection quietly resolving to a different server is the failure this
	 * guards.
	 *
	 * @since 0.4.2
	 * @return Row|null
	 */
	public static function selected_row(): ?Row {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => self::selected_slug(),
				'number'      => 1,
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}

		$row = reset( $rows );
		if ( ! $row instanceof Row ) {
			return null;
		}

		// A disabled server is not reachable remotely; it must not become
		// reachable from the browser.
		if ( empty( $row->is_enabled ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Register both options with the Settings API.
	 *
	 * `sanitize_callback` on the slug is deliberately permissive about
	 * CONTENT and strict about SHAPE: an unknown slug is allowed to be
	 * stored (the server may be created later) but resolution fails closed,
	 * so storing one is harmless and rejecting one would make ordering
	 * matter.
	 *
	 * @since 0.4.2
	 * @param string $option_group Settings group to register against.
	 * @return void
	 */
	public static function register( string $option_group ): void {
		register_setting(
			$option_group,
			self::OPTION_ENABLED,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => static fn( $value ): bool => (bool) $value,
			)
		);

		register_setting(
			$option_group,
			self::OPTION_ALLOW_EXECUTE,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => static fn( $value ): bool => (bool) $value,
			)
		);

		register_setting(
			$option_group,
			self::OPTION_SERVER,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static fn( $value ): string => sanitize_key( (string) $value ),
			)
		);
	}
}
