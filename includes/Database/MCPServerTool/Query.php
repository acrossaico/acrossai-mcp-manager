<?php
/**
 * BerlinDB Query for the MCPServerTool module.
 *
 * Presence-based selection storage — a row for `(server_id, ability_slug)`
 * IS the "added" flag. Provides two bespoke helpers on top of BerlinDB's
 * inherited public API:
 *
 *   - `get_added_slugs( $server_id )` — hot path for the enforcement gate.
 *   - `replace_set( $server_id, $desired )` — the transactional Save operation
 *     wrapped in `START TRANSACTION` + `SELECT ... FOR UPDATE` row-range lock
 *     + `COMMIT` / `ROLLBACK`. Concurrent overlapping saves on the same
 *     `server_id` serialize cleanly (FR-030, SC-011).
 *
 * Plus one utility helper for cascade cleanup:
 *
 *   - `delete_items_for_server( $server_id )` — single `$wpdb->delete()`
 *     used from the `mcp_server_deleted` cascade hook (FR-026).
 *
 * Plus a static callback for the cascade wiring itself:
 *
 *   - `on_mcp_server_deleted( $server_id, $result )` — no-op when `$result`
 *     is false; delegates to `delete_items_for_server` otherwise.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerTool
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerTool;

defined( 'ABSPATH' ) || exit;

/**
 * BerlinDB Query subclass for the per-server tools table.
 *
 * @since 0.1.0
 */
class Query extends \BerlinDB\Database\Kern\Query {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'acrossai_mcp_server_tools';

	/**
	 * SQL alias used in JOIN expressions.
	 *
	 * @var string
	 */
	protected $table_alias = 'mcpst';

	/**
	 * Schema class for this query.
	 *
	 * @var string
	 */
	protected $table_schema = Schema::class;

	/**
	 * Singular item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name = 'mcp_server_tool';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'mcp_server_tools';

	/**
	 * Row class for query results.
	 *
	 * @var string
	 */
	protected $item_shape = Row::class;

	/**
	 * Singleton instance.
	 *
	 * @var Query|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — enforces singleton pattern (A2/S6).
	 *
	 * @since 0.1.0
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Visibility override (public → private) enforces the singleton pattern per A2/S6; PHPCS misses the visibility semantics.
		parent::__construct();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @since  0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return the set of ability_slugs currently added as tools for a server.
	 *
	 * Hot path — called once per admin page load and once per MCP tool
	 * invocation (through the ToolExposureGate per-request cache).
	 *
	 * @since 0.1.0
	 *
	 * @param int $server_id The MCP server id.
	 * @return string[] Ability slugs. Order is DB row insertion order (unspecified).
	 */
	public function get_added_slugs( int $server_id ): array {
		$rows = $this->query(
			array(
				'server_id' => $server_id,
				'number'    => 0, // No cap.
			)
		);
		return array_map(
			static function ( $row ) {
				return (string) $row->ability_slug;
			},
			$rows
		);
	}

	/**
	 * Replace the full set of added ability_slugs for a server.
	 *
	 * Transactional Save operation (FR-030, SC-011). Concurrent overlapping
	 * saves on the same `server_id` serialize on the `FOR UPDATE` row-range
	 * lock — final DB state equals exactly the second-committing request's
	 * desired set, never a set-union superset.
	 *
	 * Flow:
	 *   1. Normalize input (outside TX — pure function).
	 *   2. START TRANSACTION.
	 *   3. SELECT ... FOR UPDATE — acquire exclusive row-range lock.
	 *   4. Snapshot current set.
	 *   5. Diff, insert added, delete removed.
	 *   6. COMMIT.
	 *   Catch → ROLLBACK + rethrow.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $server_id     The MCP server id.
	 * @param string[] $desired_slugs Full desired set (post-save state).
	 * @return array{added: string[], removed: string[]} The applied diff. `added`
	 *                lists rows that were actually INSERTED, which is not always
	 *                every requested slug — see the note at the insert loop.
	 * @throws \Throwable When the transaction rolls back — the original exception
	 *                   (from `add_item`, `delete_item`, or the SELECT FOR UPDATE)
	 *                   is re-thrown after `ROLLBACK`. Callers MUST catch (see
	 *                   `ToolsController::post_tools` for the canonical handler).
	 */
	public function replace_set( int $server_id, array $desired_slugs ): array {
		global $wpdb;

		// Normalize outside the transaction.
		$desired = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $desired_slugs ),
					'strlen'
				)
			)
		);

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- BerlinDB module transaction control; explicit TX shape per FR-030.

		try {
			// Acquire exclusive row-range lock on all rows for this server_id
			// so concurrent replace_set() calls on the same server serialize.
			$table = $wpdb->prefix . 'acrossai_mcp_server_tools';
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name interpolated after $wpdb->prefix concat; server_id is prepared.
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE server_id = %d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$server_id
				)
			);

			$current = $this->get_added_slugs( $server_id );
			$added   = array_values( array_diff( $desired, $current ) );
			$removed = array_values( array_diff( $current, $desired ) );

			// Report only what actually landed. `add_item()` returns false on a
			// failed INSERT — including the case where the physical table does
			// not exist, which BerlinDB's Query layer has no way to detect and
			// $wpdb reports only by returning false.
			//
			// Until 0.3.6 this loop discarded that return and the method went on
			// to report every requested slug as added. A caller asking "did the
			// write succeed?" was told yes by a function that had written
			// nothing, which is how the activation-order bug reached a release
			// branch past green CI and a manual pass. A write that cannot fail
			// visibly cannot be verified by anyone.
			$inserted = array();

			foreach ( $added as $slug ) {
				$result = $this->add_item(
					array(
						'server_id'    => $server_id,
						'ability_slug' => $slug,
					)
				);

				if ( false !== $result ) {
					$inserted[] = $slug;
				}
			}

			foreach ( $removed as $slug ) {
				$existing = $this->query(
					array(
						'server_id'    => $server_id,
						'ability_slug' => $slug,
						'number'       => 1,
					)
				);
				if ( ! empty( $existing ) ) {
					$this->delete_item( $existing[0]->id );
				}
			}

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transaction control.

			return array(
				'added'   => $inserted,
				'removed' => $removed,
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transaction control.
			throw $e;
		}
	}

	/**
	 * Bulk-delete all rows for a server_id in a single statement.
	 *
	 * Called by the FR-026 cascade cleanup on `mcp_server_deleted`. One SQL
	 * round-trip regardless of row count (SEC-020-011). Not wrapped in a
	 * transaction — deletion is idempotent and doesn't need snapshot
	 * isolation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $server_id The MCP server id whose rows to delete.
	 * @return int Number of rows deleted.
	 */
	public function delete_items_for_server( int $server_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_server_tools';
		$count = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk cascade cleanup; BerlinDB per-row delete would be N round-trips (SEC-020-011).
			$table,
			array( 'server_id' => $server_id ),
			array( '%d' )
		);
		// Flush the per-item BerlinDB cache group so stale reads don't survive.
		wp_cache_flush_group( 'acrossai_mcp_server_tool' );
		return (int) ( false === $count ? 0 : $count );
	}

	/**
	 * Static callback for the `mcp_server_deleted` cascade cleanup (FR-026).
	 *
	 * Fired by BerlinDB's `MCPServer\Query::delete_item()` after a successful
	 * server-row delete. Payload: `int $server_id, bool $result`. Both the
	 * single-row delete path (`admin/Partials/Settings.php:129`) and the
	 * bulk-delete path (`admin/Partials/Settings.php:223`) route through
	 * `delete_item()`, so this single hook covers both.
	 *
	 * No-ops when `$result` is false — a failed server delete MUST NOT
	 * trigger cascade cleanup.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $server_id The deleted server's id.
	 * @param bool $result    Whether the DB delete succeeded.
	 * @return void
	 */
	public static function on_mcp_server_deleted( int $server_id, bool $result ): void {
		if ( ! $result || $server_id <= 0 ) {
			return;
		}
		self::instance()->delete_items_for_server( $server_id );
	}
}
