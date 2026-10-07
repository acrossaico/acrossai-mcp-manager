<?php
/**
 * F095 — one-shot copy of OAuth data from the acrossai-pro companion.
 *
 * The companion owned OAuth until this feature took it back. Its four tables
 * hold live grants: access and refresh tokens, registered clients, and
 * per-user connector approvals. This class copies them into the equivalently
 * named tables this plugin now owns.
 *
 * Four properties are load-bearing, and each exists for a reason that is not
 * obvious from the code alone:
 *
 *   1. COPY, NEVER MOVE (FR-010). The source tables are the only rollback path.
 *      Tokens are stored solely as SHA-256 digests; the raw token lives on the
 *      AI client and nowhere else, so a botched copy cannot be reconstructed
 *      from anything. The companion's tables are therefore left byte-identical
 *      and are dropped in a later release, not this one.
 *
 *   2. DIGESTS TRANSFER UNALTERED (FR-008 / SC-C1). The copy is performed by
 *      `INSERT ... SELECT` so values never round-trip through PHP, where a
 *      stray trim, case fold or re-encode could silently invalidate every
 *      live grant.
 *
 *   3. BATCHED WITH A PERSISTED CURSOR (FR-011). Measured volume is small —
 *      532 rows on the most-used install — but customer sites cannot be
 *      measured before they fail, and the failure mode of a single-statement
 *      copy that times out is a retry loop with connections broken throughout.
 *
 *   4. ADMIN-SIDE ONLY. Invoked from `Main::reconcile_database_schemas()`
 *      (`admin_init` priority 3) and `Activator::activate()`, matching the
 *      plugin's five existing one-shot migrations and BerlinDB's own
 *      convention of hooking schema work to `admin_init` alone. The accepted
 *      consequence is that a site updated by auto-update or WP-CLI does not
 *      migrate until someone loads wp-admin; the release notes carry that.
 *
 * Diagnostics are deliberately austere. On repeated failure this reports the
 * table name, the cursor position and a row count — and nothing else. It must
 * never echo row contents, column values, or the database layer's last error
 * or last query, because those can embed the very digests being moved
 * (SC-C2 / CWE-532).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database
 * @since      0.3.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Copies OAuth rows from the companion's tables into this plugin's.
 *
 * Constitution: static utility in the `A15` family (stateless, no singleton,
 * no hook registration of its own). Callers wire it; it never wires itself.
 */
final class OAuthDataMigration {

	/**
	 * Set once every source table has been fully drained.
	 *
	 * Deleting it re-runs the migration, which is the documented recovery
	 * action. Re-running is safe: the copy is idempotent.
	 */
	public const DONE_OPTION = 'acrossai_mcp_oauth_migration_done';

	/**
	 * Per-table progress, as `destination stem => last copied source id`.
	 *
	 * Resume state. A run interrupted by a request timeout picks up here
	 * rather than restarting, which is what makes FR-011's resumability real
	 * rather than asserted.
	 */
	public const CURSOR_OPTION = 'acrossai_mcp_oauth_migration_cursor';

	/**
	 * Consecutive failed attempts, used to decide when to become visible.
	 */
	public const FAILURE_OPTION = 'acrossai_mcp_oauth_migration_failures';

	/**
	 * Failures tolerated silently before Site Health is told.
	 *
	 * One transient failure on a busy site is noise. A migration that is
	 * genuinely stuck retries on every admin page load, so this threshold is
	 * reached quickly when it matters.
	 */
	public const FAILURE_THRESHOLD = 3;

	/**
	 * Rows copied per table per pass.
	 *
	 * Chosen against a measured baseline of 532 rows on the most-used install:
	 * three passes clear the largest observed table while bounding worst-case
	 * request time on a site we cannot observe in advance.
	 */
	public const BATCH_SIZE = 200;

	/**
	 * Source (companion) stem => destination (this plugin) stem.
	 *
	 * Both are prefix-less; `$wpdb->prefix` is applied at query time.
	 */
	private const TABLE_MAP = array(
		'acrossai_pro_mcp_oauth_tokens'             => 'acrossai_mcp_oauth_tokens',
		'acrossai_pro_mcp_oauth_auth_codes'         => 'acrossai_mcp_oauth_auth_codes',
		'acrossai_pro_mcp_oauth_clients'            => 'acrossai_mcp_oauth_clients',
		'acrossai_pro_mcp_connector_approved_users' => 'acrossai_mcp_connector_approved_users',
	);

	/**
	 * Per-server meta keys re-attributed from the companion's prefix.
	 */
	private const META_KEY_MAP = array(
		'_acrossai_pro_server_settings' => '_acrossai_mcp_server_settings',
		'_acrossai_pro_pending_users'   => '_acrossai_mcp_pending_users',
	);

	/**
	 * Copy anything the companion left behind, once.
	 *
	 * Cheap to call: the first statement is a single option read, which is the
	 * steady state on every site after the first successful run.
	 *
	 * @since 0.3.9
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		// Nothing to do on the common case — a fresh install that never had the
		// companion. Record completion so later page loads short-circuit at the
		// option read above rather than probing INFORMATION_SCHEMA every time.
		if ( ! self::any_source_table_exists() ) {
			update_option( self::DONE_OPTION, 1, false );
			return;
		}

		$cursors  = self::read_cursors();
		$complete = true;

		foreach ( self::TABLE_MAP as $source_stem => $destination_stem ) {
			$outcome = self::copy_batch( $source_stem, $destination_stem, (int) ( $cursors[ $destination_stem ] ?? 0 ) );

			if ( null === $outcome ) {
				self::record_failure( $destination_stem, (int) ( $cursors[ $destination_stem ] ?? 0 ) );
				return;
			}

			$cursors[ $destination_stem ] = $outcome['cursor'];

			// A full batch means there is probably more; come back next pass.
			if ( $outcome['copied'] >= self::batch_size() ) {
				$complete = false;
			}
		}

		update_option( self::CURSOR_OPTION, $cursors, false );

		if ( ! $complete ) {
			return;
		}

		self::migrate_server_meta_keys();

		delete_option( self::FAILURE_OPTION );
		update_option( self::DONE_OPTION, 1, false );
	}

	/**
	 * Copy up to one batch, returning the new cursor and the row count.
	 *
	 * Returns null on failure so the caller can record it; the cursor is left
	 * untouched in that case, so the next pass retries the same window.
	 *
	 * @param  string $source_stem      Prefix-less companion table.
	 * @param  string $destination_stem Prefix-less destination table.
	 * @param  int    $cursor           Last source id already copied.
	 * @return array{cursor:int,copied:int}|null
	 */
	private static function copy_batch( string $source_stem, string $destination_stem, int $cursor ): ?array {
		global $wpdb;

		$source      = $wpdb->prefix . $source_stem;
		$destination = $wpdb->prefix . $destination_stem;

		if ( ! self::table_exists( $source ) ) {
			return array(
				'cursor' => $cursor,
				'copied' => 0,
			);
		}

		// A missing DESTINATION is a genuine failure — the caller records it and
		// retries next pass — but it must be detected by probing rather than by
		// letting a query fail. `SHOW COLUMNS` against a table that does not
		// exist emits a WordPress database error, which prints to output and,
		// on a site with WP_DEBUG_DISPLAY on, would surface mid-page.
		if ( ! self::table_exists( $destination ) ) {
			return null;
		}

		// Copy only the columns both tables actually have. Identical by design,
		// but an install carrying schema drift should migrate what it can
		// rather than fail outright on a column neither side needs.
		$columns = array_values(
			array_intersect( self::live_columns( $source ), self::live_columns( $destination ) )
		);

		if ( array() === $columns ) {
			return null;
		}

		$list = implode( ', ', array_map( static fn( string $c ): string => '`' . $c . '`', $columns ) );

		// INSERT ... SELECT keeps every value inside the database engine. The
		// digests never pass through PHP, so they cannot be altered in transit
		// (SC-C1). IGNORE makes a re-run a no-op on rows already present, which
		// together with the cursor is what makes this idempotent.
		//
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are plugin-owned ($wpdb->prefix + a hardcoded stem from TABLE_MAP) and the column list is derived from live schema, never from request data. $wpdb->prepare() cannot parameterise identifiers. The two genuine values are bound below.
		$copied = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$destination}` ({$list})
				 SELECT {$list} FROM `{$source}`
				 WHERE id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				$cursor,
				self::batch_size()
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $copied ) {
			return null;
		}

		// Advance by what the SOURCE window reached, not by rows inserted.
		// IGNORE silently skips rows already present, so counting insertions
		// would stall the cursor forever on a partially-copied table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor advance on a plugin-owned table; no caching layer applies to a one-shot migration.
		$reached = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM ( SELECT id FROM `{$source}` WHERE id > %d ORDER BY id ASC LIMIT %d ) AS window_rows", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier only; values bound.
				$cursor,
				self::batch_size()
			)
		);

		return array(
			'cursor' => null === $reached ? $cursor : (int) $reached,
			'copied' => (int) $copied,
		);
	}

	/**
	 * Re-attribute the companion's per-server meta keys to this plugin.
	 *
	 * Runs once, after every table is drained, so a partially migrated install
	 * never ends up with settings pointing at data that has not arrived.
	 *
	 * @return void
	 */
	private static function migrate_server_meta_keys(): void {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'acrossai_mcp_servers_meta';

		if ( ! self::table_exists( $meta_table ) ) {
			return;
		}

		foreach ( self::META_KEY_MAP as $from => $to ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot key rename on a plugin-owned table.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_key = %s WHERE meta_key = %s',
					$meta_table,
					$to,
					$from
				)
			);
		}
	}

	/**
	 * Rows per pass, filterable for operators with unusual volumes.
	 *
	 * @return int
	 */
	private static function batch_size(): int {
		/**
		 * Filters how many rows the OAuth migration copies per table per pass.
		 *
		 * @since 0.3.9
		 * @param int $size Default 200.
		 */
		$size = (int) apply_filters( 'acrossai_mcp_oauth_migration_batch_size', self::BATCH_SIZE );

		return max( 1, $size );
	}

	/**
	 * Whether any companion table is present.
	 *
	 * @return bool
	 */
	private static function any_source_table_exists(): bool {
		global $wpdb;

		foreach ( array_keys( self::TABLE_MAP ) as $stem ) {
			if ( self::table_exists( $wpdb->prefix . $stem ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a table physically exists.
	 *
	 * @param  string $table Fully prefixed table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence probe; no caching layer applies.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Column names a table actually has right now.
	 *
	 * Read from live schema rather than the declared Schema class so that an
	 * install carrying drift is described accurately.
	 *
	 * @param  string $table Fully prefixed table name.
	 * @return string[]
	 */
	private static function live_columns( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema read; no caching layer applies.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Stored cursors, normalised.
	 *
	 * @return array<string,int>
	 */
	private static function read_cursors(): array {
		$stored = get_option( self::CURSOR_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$cursors = array();

		foreach ( $stored as $stem => $id ) {
			if ( is_string( $stem ) && is_numeric( $id ) ) {
				$cursors[ $stem ] = (int) $id;
			}
		}

		return $cursors;
	}

	/**
	 * Record a failed pass and, past the threshold, make it visible.
	 *
	 * The payload is deliberately limited to the table name and cursor
	 * position. It must never carry row contents, column values, or
	 * `$wpdb->last_error` / `last_query`, any of which can embed the credential
	 * digests this class moves (SC-C2 / CWE-532).
	 *
	 * @param  string $destination_stem Table that failed.
	 * @param  int    $cursor           Position it stalled at.
	 * @return void
	 */
	private static function record_failure( string $destination_stem, int $cursor ): void {
		$failures = (int) get_option( self::FAILURE_OPTION, 0 ) + 1;

		update_option( self::FAILURE_OPTION, $failures, false );

		if ( $failures < self::FAILURE_THRESHOLD ) {
			return;
		}

		/**
		 * Fires when the OAuth migration has failed repeatedly.
		 *
		 * Listeners MUST NOT be passed row data — see the class docblock.
		 *
		 * @since 0.3.9
		 * @param string $table    Destination table stem that stalled.
		 * @param int    $cursor   Source id the copy had reached.
		 * @param int    $failures Consecutive failed attempts.
		 */
		do_action( 'acrossai_mcp_oauth_migration_stalled', $destination_stem, $cursor, $failures );

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a stalled credential migration; bounded to table name, cursor and attempt count by SC-C2.
			sprintf(
				'[acrossai-mcp-manager] OAuth data migration stalled on table %1$s at cursor %2$d after %3$d attempts. No row data is included by design.',
				$destination_stem,
				$cursor,
				$failures
			)
		);
	}
}
