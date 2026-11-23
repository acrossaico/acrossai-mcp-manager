<?php
/**
 * Issue #293 — ask MySQL to refuse writes, instead of parsing for them.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.44
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps one read in `START TRANSACTION READ ONLY`.
 *
 * **Why.** The read-only guard on `run-db-select-query` was a regex over the first keyword, and
 * #293 showed what that costs: a statement beginning with `SELECT` can still write. Rather than
 * chase spellings, ask the server to enforce it — inside a read-only transaction MySQL rejects any
 * statement that modifies a table, however it is written, and the guarantee comes from the engine
 * rather than from our pattern.
 *
 * **What this does not claim.** It is defence in depth, not the whole answer:
 *
 * - `INTO OUTFILE` writes to the *filesystem*, not to a table. Whether a read-only transaction
 *   refuses it is version- and configuration-dependent, and it could not be established on the
 *   development host used here because `secure_file_priv` is `NULL`, which blocks file export
 *   outright and masks the transaction's behaviour. So `INTO OUTFILE` is refused explicitly by
 *   {@see Db_Read_Only_Sql::file_write_clause()} and this class is not relied on for it.
 * - A server that will not start a read-only transaction degrades to a plain query. The explicit
 *   refusals still apply, so the statement is no less guarded than before this class existed.
 *
 * Both are stated here rather than discovered later, because "runs in a read-only transaction" is
 * the kind of sentence that invites more trust than it has earned.
 *
 * @since 0.0.44
 */
final class Db_Read_Only_Session {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Start a read-only transaction and hand back the closer.
	 *
	 * The closer is always safe to call, and is a no-op when no transaction was started — so the
	 * caller needs no conditional in its `finally`.
	 *
	 * @since  0.0.44
	 * @return callable(): void
	 */
	public static function begin(): callable {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return static function (): void {};
		}

		$suppressed = $wpdb->suppress_errors( true );
		$last_error = $wpdb->last_error;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, not a data query.
		$started = $wpdb->query( 'START TRANSACTION READ ONLY' );

		$failed = ( false === $started ) || '' !== (string) $wpdb->last_error;

		// Never leave our own probe in $wpdb->last_error: the caller reports that field to the user
		// when the real query fails, and a stale transaction error there would be reported as the
		// reason the query failed.
		$wpdb->last_error = $last_error;
		$wpdb->suppress_errors( $suppressed );

		if ( $failed ) {
			return static function (): void {};
		}

		return static function () use ( $wpdb, $last_error ): void {
			$suppressed = $wpdb->suppress_errors( true );

			// COMMIT, not ROLLBACK: nothing was written, so either ends the transaction, and COMMIT
			// does not look like an error in a slow query log.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
			$wpdb->query( 'COMMIT' );

			$wpdb->last_error = $last_error;
			$wpdb->suppress_errors( $suppressed );
		};
	}
}
