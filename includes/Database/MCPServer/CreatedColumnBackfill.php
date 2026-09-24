<?php
/**
 * F091 — give values to the columns the reconciler just created.
 *
 * Restoring a missing column is not the same as repairing the site. A restored
 * column arrives carrying its declared DEFAULT, and for the three protocol-tool
 * flags that default is ENABLED. `Schema.php` says why in as many words:
 *
 *   > Default 1 means "enabled" and, on the ALTER for existing installs,
 *   > backfills every pre-F025 row with all three protocol tools enabled.
 *
 * That was correct when `mcp-adapter` was the only server type. It is wrong for
 * an `acrossai` server, whose type declares Toolsets instead — so a schema-only
 * repair leaves the managed server advertising three tools its operator never
 * selected, which is the visible symptom F091 exists to remove.
 *
 * THE LICENCE TO WRITE IS DELIBERATELY NARROW. This acts ONLY on columns the
 * reconciler created in this same request. That is `SeededToolsBackfill`'s
 * doctrine applied to a column instead of a row: an operator is entitled to
 * their choices, and a repair that re-asserts a value would fight them on every
 * admin request. A column that did not physically exist moments ago cannot have
 * carried a preference, so writing it once cannot overwrite intent. Anything
 * already stored is none of this class's business.
 *
 * ORDER INSIDE `apply()` IS LOAD-BEARING — see the comments there. It is the
 * difference between repairing the bug and reproducing it.
 *
 * A11 pure-service exception (stateless, static-only — no singleton, no ctor).
 * See docs/memory/ARCHITECTURE.md §A11.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.3.7 (F091)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Value repair for just-created server columns.
 *
 * @since 0.3.7 (F091)
 */
final class CreatedColumnBackfill {

	/**
	 * Give newly created columns the values they would have had.
	 *
	 * @since  0.3.7 (F091)
	 * @param  string[] $created Columns the reconciler created on the servers table.
	 * @return void
	 */
	public static function apply( array $created ): void {
		if ( array() === $created ) {
			return;
		}

		$created_map = array_flip( array_map( 'strval', $created ) );

		/*
		 * STEP 1 — the type, before anything reads it.
		 *
		 * On a site stamped at the current version the `server_type` column is
		 * itself missing, so the reconciler creates it and every row lands on
		 * the column default, `mcp-adapter`. The 1.1.6 migration's corrective
		 * UPDATE cannot help: it is gated inside a callback that will never run
		 * again on a stamped table.
		 *
		 * If step 2 read the type at that moment it would resolve `mcp-adapter`
		 * to the legacy tool list — the three protocol tools — and write all
		 * three flags ENABLED on the managed server, reproducing the exact
		 * symptom this feature removes AND mistyping the server permanently.
		 */
		if ( isset( $created_map['server_type'] ) ) {
			self::retype_seeded_servers();
			self::release_server_guide_backfill();
		}

		// STEP 2 — the protocol flags, now that the type can be trusted.
		self::backfill_protocol_flags( $created_map );

		// The seeder owns this cache key; any writer of these rows must clear it.
		wp_cache_delete( 'all_servers', 'acrossai_mcp' );
	}

	/**
	 * Restore the declared type on every server the plugin seeds.
	 *
	 * Generalises `Table::upgrade_to_1_1_6()`'s slug-matched UPDATE over
	 * `ServerTypes::seeded_servers()`, so shipping a third seeded type needs no
	 * new hardcoded statement here (Constitution VI).
	 *
	 * Matches on slug and only overwrites the column default, so a server an
	 * operator has since retyped by hand is not in scope — its row no longer
	 * reads `mcp-adapter` unless that is what the operator chose.
	 *
	 * @since  0.3.7 (F091)
	 * @return void
	 */
	private static function retype_seeded_servers(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		foreach ( ServerTypes::seeded_servers() as $slug => $seeded ) {
			$type = isset( $seeded['type'] ) ? (string) $seeded['type'] : '';

			if ( '' === $type || '' === (string) $slug ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted one-shot repair of a column created moments ago; cache cleared by the caller.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET server_type = %s WHERE server_slug = %s AND server_type = %s',
					$table,
					$type,
					(string) $slug,
					'mcp-adapter'
				)
			);
		}
	}

	/**
	 * Let the server-guide backfill run, now that it can.
	 *
	 * That repair selects `WHERE s.server_type = %s`. On a table without the
	 * column the query errors, returns nothing, its loop does nothing — and it
	 * writes its done-flag anyway. So on precisely the sites F091 targets it has
	 * already recorded success without doing its work, and restoring the column
	 * is not enough to make it run. Clearing the flag is.
	 *
	 * @since  0.3.7 (F091)
	 * @return void
	 */
	private static function release_server_guide_backfill(): void {
		delete_option( ServerGuideBackfill::DONE_OPTION );
	}

	/**
	 * Set the protocol-tool flags from each server's declared type.
	 *
	 * @since  0.3.7 (F091)
	 * @param  array<string, int> $created_map Created column names as keys.
	 * @return void
	 */
	private static function backfill_protocol_flags( array $created_map ): void {
		$flag_columns = array_intersect_key( ToolPolicy::COLUMN_MAP, $created_map );

		if ( array() === $flag_columns ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot repair read; the rows were just altered, so a cached read would be stale by definition.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, server_type FROM %i', $table ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$server_id = (int) ( $row['id'] ?? 0 );
			$type_slug = (string) ( $row['server_type'] ?? '' );

			if ( $server_id <= 0 || '' === $type_slug ) {
				continue;
			}

			$type = ServerTypes::get( $type_slug );

			/*
			 * Skip a type that declares nothing.
			 *
			 * `ServerTypes::declared_tools()` falls back to the LEGACY type when
			 * a type's own tool list is empty — and the legacy list IS the three
			 * protocol tools. Trusting the accessor here would mean a
			 * third-party type registered with `'tools' => array()` had all
			 * three flags switched ON by a repair. Leaving the column default
			 * alone is the honest answer for a type nobody can describe.
			 */
			if ( null === $type || empty( $type['tools'] ) || ! is_array( $type['tools'] ) ) {
				continue;
			}

			$columns = ToolPolicy::split_payload( ServerTypes::declared_tools( $type_slug ) )['columns'];

			// ONLY the columns this pass created. An untouched flag column holds
			// whatever the operator chose and is not ours to rewrite.
			$writable = array_intersect_key( $columns, $flag_columns );

			if ( array() === $writable ) {
				continue;
			}

			MCPServerQuery::instance()->update_item( $server_id, $writable );
		}
	}
}
