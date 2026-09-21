<?php
/**
 * Plugin-managed MCP server seeder / reconciler.
 *
 * Originally extracted from MCPServer\Table in Feature 011 as a one-shot
 * insert for the single "Default MCP Server" row. Feature 088 generalised it
 * into a declarative reconciler that owns EVERY plugin-managed server row.
 *
 * Stateless pure service helper (A11/A15) — no singleton, no ctor.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent seeder + reconciler for the plugin-managed MCP server rows.
 *
 * Called by Activator::activate() immediately after
 * MCPServer\Table::instance()->maybe_upgrade() (fresh installs) and by
 * Settings::maybe_seed_default_server() on `admin_init` priority 4 (in-place
 * plugin updates + auto-heal after a manual delete).
 *
 * Each definition splits its columns into two buckets:
 *
 *   - `managed` — plugin-owned. Written on INSERT and re-asserted on every
 *     run, so a future plugin version that adds a column backfills existing
 *     rows on the next admin request rather than only on fresh installs.
 *   - `initial` — operator-owned. Written ONCE at INSERT and never re-forced,
 *     so the operator's Enable/Disable choice (and any future operator-facing
 *     setting) survives.
 *
 * To add a column in a future version: add it to Schema::$columns, Row, bump
 * Table::$version with an upgrade_to_* callback, then add the key to the
 * relevant bucket below. Nothing else in this class changes — the $wpdb
 * placeholder formats are derived from the value types, not hand-maintained.
 *
 * DELIBERATE: ownership is decided by slug alone — there is no marker
 * recording which rows this seeder inserted. A row that already carries a
 * managed slug is ADOPTED: its managed columns are rewritten to the values
 * below and it becomes non-editable/non-deletable via ProtectedServers. On an
 * install that happened to create its own `acrossai-mcp-server` before
 * upgrading, that silently replaces the operator's server configuration.
 * Accepted (F088) in favour of keeping one code path; revisit by recording
 * inserted row ids in an option if the trade ever stops being worth it.
 *
 * Also NOT checked here: `server_route_namespace` + `server_route`
 * uniqueness. Nothing in the plugin enforces it (pre-F088 behaviour), the
 * vendor adapter dedupes on slug only, and register_rest_route() appends
 * handlers — so two servers sharing a route both register and the first one
 * dispatched wins. Out of scope for F088.
 */
final class DefaultServerSeeder {

	/**
	 * Default MCP server slug — relocated here from MCPServer\Table in Feature 011 (FR-022).
	 */
	public const SLUG = 'mcp-adapter-default-server';

	/**
	 * Feature 088 — the AcrossAI-branded server slug. Seeded again since 0.3.6.
	 *
	 * Withdrawn in F090 and restored here, which is worth stating plainly
	 * rather than leaving as a silent revert. The row was never the problem:
	 * it arrived carrying the WRONG TOOLS, because the `acrossai` type shipped
	 * with an empty list and only the sibling could fill it — so a server
	 * created before the add-on was installed got mcp-adapter's three tools
	 * under an AcrossAI label. Withdrawing the row removed the symptom.
	 *
	 * 0.3.6 moved the Toolset layer into this plugin, so the type can declare
	 * its own fourteen tools up front. The row comes back with the right ones,
	 * dormant until the add-on registers the abilities behind them.
	 *
	 * `Table::upgrade_to_1_1_6()` also names this constant, to correct the type
	 * on installs that already carry the row from 0.3.4.
	 */
	public const ACROSSAI_SLUG = 'acrossai-mcp-server';

	/**
	 * Declarative definitions for every plugin-managed server row, keyed by slug.
	 *
	 * Derived from `ServerTypes::seeded_servers()` since 0.3.6 rather than
	 * hardcoded here. One entry in the type registry now yields one seeded
	 * server, which is what makes a future server type a single-place change —
	 * and it is what stops the type and its server disagreeing about tools, the
	 * way they did when an AcrossAI server arrived carrying mcp-adapter's.
	 *
	 * @return array<string, array{managed: array<string, mixed>, initial: array<string, mixed>, tools: string[]}>
	 */
	private static function definitions(): array {
		$definitions = array();

		foreach ( ServerTypes::seeded_servers() as $slug => $seeded ) {
			$definitions[ $slug ] = array(
				// Plugin-owned identity, re-asserted every run.
				'managed' => $seeded['server'],
				'initial' => array(
					'is_enabled'  => 0,
					// OPERATOR-owned, despite being the plugin that chose it.
					//
					// This sat in `managed` until 0.3.6, so the reconciler
					// rewrote it on every admin request: switching a seeded
					// server's type appeared to work, survived the save, and
					// was silently reverted by the next page load. The Tools
					// tab offers a Server type control, and a control whose
					// result is undone before the operator sees it again is
					// worse than no control.
					//
					// Written at INSERT so a new install still gets the right
					// type; never re-forced, so the choice sticks. The 1.1.6
					// migration that backfills this column is unaffected —
					// that is a migration, not the reconciler.
					'server_type' => $seeded['type'],
				),
				// NOT a column. Consumed after INSERT by `seed()`, because
				// curated rows are keyed by the id the INSERT produces.
				'tools'   => $seeded['tools'],
			);
		}

		return $definitions;
	}

	/**
	 * Idempotently seed + reconcile every plugin-managed MCP server row.
	 *
	 * Costs one SELECT per definition on a healthy install; INSERT/UPDATE only
	 * run when the row is missing or a managed column has drifted.
	 *
	 * @return void
	 */
	public static function seed(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'acrossai_mcp_servers';
		$known   = self::schema_columns();
		$touched = false;

		foreach ( self::definitions() as $slug => $definition ) {
			$managed = array_intersect_key( $definition['managed'], $known );
			$initial = array_intersect_key( $definition['initial'], $known );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE server_slug = %s LIMIT 1', $table, $slug ),
				ARRAY_A
			);

			if ( null === $existing ) {
				$data = array_merge( $managed, $initial );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert( $table, $data, self::formats( $data ) );
				self::write_declared_tools( (int) $wpdb->insert_id, $definition['tools'] ?? array() );
				$touched = true;
				continue;
			}

			$drift = self::drifted_columns( $managed, $existing );
			if ( empty( $drift ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				$drift,
				array( 'id' => (int) $existing['id'] ),
				self::formats( $drift ),
				array( '%d' )
			);
			$touched = true;
		}

		if ( $touched ) {
			wp_cache_delete( 'all_servers', 'acrossai_mcp' );
		}
	}

	/**
	 * Put the type's DECLARED tools on a freshly inserted server.
	 *
	 * Declared, emphatically not `ServerTypes::tools_for()`. That narrows to
	 * abilities registered right now, and at seed time — plugin activation, or
	 * `admin_init` on the update that adds a row — every `toolset/*` slug
	 * narrows away, leaving the AcrossAI server empty all over again. That is
	 * the bug this release fixes, so resolving through the narrowing path here
	 * would reintroduce it at the one moment it matters most.
	 *
	 * Writing slugs for abilities that do not exist yet is safe and is the
	 * point: an unregistered tool is never advertised to a client, the Tools
	 * tab filters it out of what it offers, and the row lights up the moment
	 * the add-on registers it. INSERT-only; a later run never re-forces these,
	 * so the operator's curation is never overwritten.
	 *
	 * @param  int      $server_id The id the INSERT produced.
	 * @param  string[] $tools     Slugs the type declares.
	 * @return void
	 */
	private static function write_declared_tools( int $server_id, array $tools ): void {
		if ( $server_id <= 0 || array() === $tools ) {
			return;
		}

		$split = ToolPolicy::split_payload( $tools );

		MCPServerQuery::instance()->update_item( $server_id, $split['columns'] );

		if ( array() !== $split['curated'] ) {
			MCPServerToolQuery::instance()->replace_set( $server_id, $split['curated'] );
		}
	}

	/**
	 * Managed columns whose stored value no longer matches the definition.
	 *
	 * Columns absent from the fetched row are skipped — that is the physical
	 * guard for a definition shipped ahead of its ALTER TABLE (the row is read
	 * with SELECT *, so a not-yet-added column simply is not present).
	 *
	 * @param array<string, mixed>  $managed  Managed columns from the definition.
	 * @param array<string, string> $existing Stored row as an associative array.
	 * @return array<string, mixed> Columns to write, empty when nothing drifted.
	 */
	private static function drifted_columns( array $managed, array $existing ): array {
		$drift = array();

		foreach ( $managed as $column => $value ) {
			if ( ! array_key_exists( $column, $existing ) ) {
				continue;
			}
			if ( (string) $existing[ $column ] !== (string) $value ) {
				$drift[ $column ] = $value;
			}
		}

		return $drift;
	}

	/**
	 * Column names declared by the BerlinDB Schema, as a set keyed by name.
	 *
	 * Used to drop definition keys that the current code base has no column
	 * for, so a stale definition degrades to a skipped column instead of a
	 * $wpdb error on every admin request.
	 *
	 * Read via reflection on the DECLARED default value rather than by
	 * instantiating Schema: BerlinDB's Boot trait normalises $columns into
	 * Column objects when a Schema is constructed, and this helper only needs
	 * the raw `name` keys.
	 *
	 * @return array<string, true>
	 */
	private static function schema_columns(): array {
		$defaults = ( new \ReflectionClass( Schema::class ) )->getDefaultProperties();
		$columns  = isset( $defaults['columns'] ) && is_array( $defaults['columns'] )
			? $defaults['columns']
			: array();

		$names = array();
		foreach ( $columns as $column ) {
			if ( is_array( $column ) && isset( $column['name'] ) ) {
				$names[ (string) $column['name'] ] = true;
			}
		}

		return $names;
	}

	/**
	 * Derive $wpdb placeholder formats from the value types.
	 *
	 * Hand-maintained format lists break silently when a key is added in the
	 * middle of the data array; deriving them keeps "add a column" a one-line
	 * change to definitions().
	 *
	 * @param array<string, mixed> $data Column => value pairs.
	 * @return array<int, string> Placeholder formats in $data order.
	 */
	private static function formats( array $data ): array {
		$formats = array();

		foreach ( $data as $value ) {
			if ( is_int( $value ) || is_bool( $value ) ) {
				$formats[] = '%d';
			} elseif ( is_float( $value ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
