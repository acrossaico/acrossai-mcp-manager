<?php
/**
 * Issue #293 — what a read-only SQL statement may not do.
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
 * Pure checks on a raw statement, with no database and no global state.
 *
 * **Why this is a separate class.** The two defects in #293 lived inside
 * `Run_Db_Select_Query::execute()`, mixed in with `$wpdb` calls, which is why they had no test that
 * could run: the surrounding method needs a database, so #298's `has_db()` skip swallowed the lot.
 * Every decision here is a pure function of the statement text, so it is testable in the stub
 * harness and actually executes in CI.
 *
 * This is deliberately *not* the security boundary on its own. The boundary is MySQL refusing the
 * statement (a read-only transaction) plus {@see Db_Sql_Tables} resolving which tables it touches.
 * These checks are the cheap first filter that produces a legible refusal.
 *
 * @since 0.0.44
 */
final class Db_Read_Only_Sql {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Statement verbs a read-only query ability may begin with.
	 *
	 * @since 0.0.44
	 * @var   string[]
	 */
	public const ALLOWED_VERBS = array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' );

	/**
	 * Strip SQL comments so a keyword cannot hide behind one.
	 *
	 * @since  0.0.44
	 * @param  string $sql Raw statement.
	 * @return string
	 */
	public static function strip_comments( string $sql ): string {
		return (string) preg_replace( '#/\*.*?\*/|--[^\n]*|\#[^\n]*#s', ' ', $sql );
	}

	/**
	 * The statement's leading keyword, upper-cased, comments removed.
	 *
	 * @since  0.0.44
	 * @param  string $sql Raw statement.
	 * @return string Empty when there is no word to read.
	 */
	public static function first_verb( string $sql ): string {
		preg_match( '/^\s*(\w+)/', self::strip_comments( $sql ), $m );

		return strtoupper( (string) ( $m[1] ?? '' ) );
	}

	/**
	 * Whether the leading keyword is one this ability accepts.
	 *
	 * @since  0.0.44
	 * @param  string $sql Raw statement.
	 * @return bool
	 */
	public static function verb_allowed( string $sql ): bool {
		return in_array( self::first_verb( $sql ), self::ALLOWED_VERBS, true );
	}

	/**
	 * The file-writing clause in a statement, or null when there is none.
	 *
	 * `SELECT … INTO OUTFILE` and `INTO DUMPFILE` begin with `SELECT`, so the verb guard passes them.
	 * On a host whose `secure_file_priv` is empty or points somewhere writable, that turns a
	 * read-only query ability into an arbitrary file write — and one that never passes
	 * {@see Path_Allowlist_Guard}, so the file manager's write allowlist is routed around entirely
	 * by a different subsystem. That is the part that makes this worth an explicit refusal rather
	 * than leaving it to the server.
	 *
	 * Comments are stripped first, so `SELECT 1 INTO/*x*\/OUTFILE '/tmp/p'` is still caught.
	 *
	 * @since  0.0.44
	 * @param  string $sql Raw statement.
	 * @return string|null `OUTFILE` or `DUMPFILE`, upper-cased.
	 */
	public static function file_write_clause( string $sql ): ?string {
		if ( 1 === preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', self::strip_comments( $sql ), $m ) ) {
			return strtoupper( $m[1] );
		}

		return null;
	}

	/**
	 * Whether the statement already ends in its own `LIMIT` clause.
	 *
	 * The old test was `preg_match( '/\bLIMIT\b/i', $sql )`, which matches a `LIMIT` *anywhere* —
	 * inside a subquery, or inside a string literal. When it matched, no outer `LIMIT` was appended
	 * and the row cap was simply absent while the response still said `truncated: false`. Both
	 * measured:
	 *
	 *     SELECT * FROM (SELECT 1 LIMIT 1) t      -> cap not applied
	 *     SELECT "LIMIT" AS a FROM wp_posts       -> cap not applied
	 *
	 * This anchors to the end of the statement, past an optional trailing semicolon and whitespace,
	 * and accepts the `LIMIT n` and `LIMIT off, n` forms. A `LIMIT` buried mid-statement no longer
	 * suppresses the cap.
	 *
	 * Appending is only an optimisation either way: the caller enforces the cap by slicing the rows
	 * it got back, so a wrong answer here costs a wider fetch, never a missing cap.
	 *
	 * @since  0.0.44
	 * @param  string $sql Raw statement.
	 * @return bool
	 */
	public static function has_trailing_limit( string $sql ): bool {
		$stripped = rtrim( self::strip_comments( $sql ) );

		return 1 === preg_match( '/\bLIMIT\s+\d+(\s*,\s*\d+)?\s*;?\s*$/i', $stripped );
	}

	/**
	 * Append a `LIMIT` when the statement has none and the verb accepts one.
	 *
	 * `SHOW`, `DESCRIBE` and `EXPLAIN` either reject `LIMIT` or ignore it, so they are left alone.
	 *
	 * @since  0.0.44
	 * @param  string $sql   Raw statement.
	 * @param  int    $limit Row ceiling; zero or less appends nothing.
	 * @return string
	 */
	public static function with_limit( string $sql, int $limit ): string {
		if ( $limit < 1 || 'SELECT' !== self::first_verb( $sql ) || self::has_trailing_limit( $sql ) ) {
			return $sql;
		}

		return rtrim( $sql, "; \t\n\r" ) . ' LIMIT ' . $limit;
	}
}
