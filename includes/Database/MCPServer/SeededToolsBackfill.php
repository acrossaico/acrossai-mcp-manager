<?php
/**
 * Repair for seeded servers that lost their tools to the activation-order bug.
 *
 * **What went wrong.** Until 0.3.6, `Activator::activate()` ran
 * `DefaultServerSeeder::seed()` before it created the server-tools table. The
 * seeder INSERTed its rows, then wrote each type's curated tool slugs into a
 * table that did not exist yet. Nothing threw — `$wpdb` returns false and
 * BerlinDB's Query layer has no DDL of its own — so activation reported success
 * while every `toolset/*` row on the seeded AcrossAI server was dropped.
 *
 * **Why it cannot heal itself.** Curated rows are written once, at INSERT. The
 * `admin_init` reconcile run finds the server row already present, takes the
 * drift branch, and never reaches the curated write again. Without this class
 * an affected site would carry an empty AcrossAI server forever, and the only
 * recovery would be deleting the server so the seeder re-INSERTs it.
 *
 * **Why the condition is ZERO rows, not "missing some".** An operator is
 * entitled to remove tools, and a backfill that tops a set back up would fight
 * them on every admin request. A *seeded* server with no curated rows at all is
 * not a curation anyone performs — the Tools tab warns loudly about serving
 * nothing — so it is the one state that reliably means the write was lost.
 * Narrow enough to be safe, broad enough to catch every affected install.
 *
 * Scoped to slugs in `ServerTypes::seeded_servers()`, so an operator's own
 * servers are never touched.
 *
 * Companion to {@see ServerGuideBackfill}, which repairs a different gap on the
 * legacy type; both write through {@see CuratedToolWriter}.
 *
 * Stateless pure service (A11 exemption) — no singleton, no ctor, no state.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.3.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

/**
 * Restores declared tools to a seeded server that has none.
 *
 * @since 0.3.6
 */
final class SeededToolsBackfill {

	/**
	 * Option flag marking the backfill as done.
	 *
	 * Deleting it re-runs the backfill, which is the documented way to recover a
	 * seeded server's tools after the fact.
	 */
	public const DONE_OPTION = 'acrossai_mcp_seeded_tools_backfilled';

	/**
	 * Give every empty seeded server its type's declared tools, once.
	 *
	 * The done-flag is written LAST, so a fatal midway leaves it unset and the
	 * next admin request retries rather than recording a half-run.
	 *
	 * @since  0.3.6
	 * @return void
	 */
	public static function maybe_backfill(): void {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		foreach ( ServerTypes::seeded_servers() as $slug => $seeded ) {
			if ( array() === $seeded['tools'] ) {
				continue;
			}

			$server_id = self::empty_server_id( (string) $slug );

			if ( 0 === $server_id ) {
				continue;
			}

			// DECLARED, exactly as the seeder would have written at INSERT. Not
			// `tools_for()` — that narrows to abilities registered right now,
			// which on a site without the add-on is the empty set that made
			// this repair necessary in the first place.
			CuratedToolWriter::add_missing( $server_id, $seeded['tools'] );
		}

		update_option( self::DONE_OPTION, 1, false );
	}

	/**
	 * The id of a seeded server carrying NO curated rows, or 0.
	 *
	 * @since  0.3.6
	 * @param  string $slug Seeded server slug.
	 * @return int
	 */
	private static function empty_server_id( string $slug ): int {
		global $wpdb;

		$servers = $wpdb->prefix . 'acrossai_mcp_servers';
		$tools   = $wpdb->prefix . 'acrossai_mcp_server_tools';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot upgrade path; no cache layer spans two plugin tables.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT s.id FROM %i AS s
				 WHERE s.server_slug = %s
				   AND NOT EXISTS (
				       SELECT 1 FROM %i AS t WHERE t.server_id = s.id
				   )
				 LIMIT 1',
				$servers,
				$slug,
				$tools
			)
		);

		return null === $id ? 0 : (int) $id;
	}
}
