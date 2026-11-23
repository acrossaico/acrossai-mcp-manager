<?php
/**
 * Feature 134 — which tables a raw SQL statement actually touches.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Asks MySQL which tables a statement reads, instead of guessing.
 *
 * **The problem this solves.** `database/run-db-select-query` takes a whole statement, so the table
 * is not a parameter and a denylist checked against parameters never sees it. Measured before this
 * existed, with `users` permanently denied:
 * `SELECT user_login, user_pass FROM wp_users` returned rows. The "always denied, not configurable"
 * promise was false for the one ability most likely to be pointed at a sensitive table.
 *
 * **Why not a regex, and why not a parser library.** A regex over `FROM`/`JOIN` finds the easy cases
 * and misses the ones that matter: a CTE, a derived table, a view, a subquery three levels down, or
 * an alias that hides which table is really being read. A parser library would handle those and
 * costs a dependency that then has to track MySQL's grammar.
 *
 * MySQL already has a parser, and it will tell us what it decided. `EXPLAIN` the statement — which
 * plans but does not execute it — then read `SHOW WARNINGS`, where MySQL returns the statement
 * rewritten with every table fully qualified. Verified against this: a CTE
 * (`WITH c AS (SELECT … FROM wp_users)`), a derived table, a three-level subquery and
 * `INTO OUTFILE` all resolve to their real tables, and an aliased join resolves `p`/`u` back to
 * `wp_posts`/`wp_users`.
 *
 * The statement is EXPLAINed, never run, so this adds a planning round trip and no side effects.
 *
 * The regex scan is kept as a fallback and its results are UNIONED in, never substituted: `EXPLAIN`
 * refuses `SHOW` statements outright, and `SHOW COLUMNS FROM wp_users` still names a table worth
 * denying.
 *
 * @since 0.0.45
 */
final class Db_Sql_Tables {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Every table a statement references, as physical names.
	 *
	 * @since  0.0.45
	 * @param  string $sql Raw statement.
	 * @return string[]
	 */
	public static function referenced( string $sql ): array {
		$sql = trim( $sql );

		if ( '' === $sql ) {
			return array();
		}

		// Union, not fallback: each finds something the other can miss.
		$tables = array_merge( self::from_explain( $sql ), self::from_scan( $sql ) );

		return array_values( array_unique( array_filter( $tables ) ) );
	}

	/**
	 * Tables according to MySQL's own parse of the statement.
	 *
	 * Returns an empty array whenever MySQL will not plan the statement — a `SHOW`, a syntax error,
	 * a server that does not emit the rewritten form. The caller unions this with the scan, so an
	 * empty answer here narrows nothing.
	 *
	 * @since  0.0.45
	 * @param  string $sql Raw statement.
	 * @return string[]
	 */
	private static function from_explain( string $sql ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}

		// A statement already carrying its own EXPLAIN would become EXPLAIN EXPLAIN.
		if ( 1 === preg_match( '/^\s*EXPLAIN\b/i', $sql ) ) {
			$sql = (string) preg_replace( '/^\s*EXPLAIN\b/i', '', $sql );
		}

		$suppressed = $wpdb->suppress_errors( true );
		$last       = $wpdb->last_error;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- planning the caller's own statement; it is never executed.
		$wpdb->get_results( 'EXPLAIN ' . $sql );

		$failed = '' !== (string) $wpdb->last_error;

		if ( $failed ) {
			$wpdb->last_error = $last;
			$wpdb->suppress_errors( $suppressed );

			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL's rewritten form of the statement just planned.
		$warnings = $wpdb->get_results( 'SHOW WARNINGS', ARRAY_A );

		$wpdb->last_error = $last;
		$wpdb->suppress_errors( $suppressed );

		if ( ! is_array( $warnings ) || array() === $warnings ) {
			return array();
		}

		$normalised = '';

		foreach ( $warnings as $row ) {
			$normalised .= ' ' . (string) ( $row['Message'] ?? '' );
		}

		return self::qualified_names( $normalised );
	}

	/**
	 * Table names out of MySQL's rewritten statement.
	 *
	 * Matches `` `db`.`name` `` for the current database only, and excludes anything immediately
	 * followed by a further `.` — MySQL qualifies column references the same way
	 * (`` `local`.`p`.`ID` ``), so without the lookahead every alias is collected as if it were a
	 * table.
	 *
	 * @since  0.0.45
	 * @param  string $normalised MySQL's rewritten statement.
	 * @return string[]
	 */
	private static function qualified_names( string $normalised ): array {
		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';

		if ( '' === $database ) {
			return array();
		}

		$pattern = '/`' . preg_quote( $database, '/' ) . '`\.`([A-Za-z0-9_]+)`(?!\s*\.)/';

		if ( ! preg_match_all( $pattern, $normalised, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Tables named in the obvious places, found without asking the database.
	 *
	 * Comments are stripped first so a name cannot be hidden behind one, and so a commented-out
	 * clause does not produce a refusal that makes no sense to the caller.
	 *
	 * @since  0.0.45
	 * @param  string $sql Raw statement.
	 * @return string[]
	 */
	private static function from_scan( string $sql ): array {
		$stripped = (string) preg_replace( '#/\*.*?\*/|--[^\n]*|\#[^\n]*#s', ' ', $sql );

		if ( ! preg_match_all( '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+`?([A-Za-z0-9_]+)`?/i', $stripped, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}
}
