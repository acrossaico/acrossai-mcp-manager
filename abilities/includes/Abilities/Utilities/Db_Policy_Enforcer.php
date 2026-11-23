<?php
/**
 * Feature 134 — applies the database policy to every database ability, in one place.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the execute callback of every `acrossai-database` ability with the policy checks.
 *
 * **Why a wrapper and not nineteen edits.** The database abilities have no shared base class — each
 * extends `Ability_Definition` and supplies its own `execute()`. Adding guard calls to all of them
 * would mean the policy is enforced in nineteen places, any one of which can be forgotten, and the
 * twentieth ability would ship with no policy at all and nothing to notice. Hooking
 * `wp_register_ability_args` puts it in one place and makes every future database ability inherit it
 * by default — the same reasoning that makes `Base_Rank_Math_Ability` the sole guard-order enforcer
 * for its suite.
 *
 * **Which abilities write is declared, not derived.** {@see self::WRITES} is an explicit list. It
 * would be shorter to read `annotations.readonly`, and that is exactly what this must not do:
 * annotations are metadata, they are set by the same hand that writes the ability, and an ability
 * mislabelled `readonly: true` would then be allowed to write in read-only mode. Novamira's read
 * mode refuses to derive authorization from them for this reason, and
 * {@see Test_Db_Policy_Enforcer} asserts the list covers every registered `database/*` ability so
 * the declaration cannot silently fall behind.
 *
 * @since 0.0.45
 */
final class Db_Policy_Enforcer {

	/**
	 * The ability category this applies to.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const CATEGORY = 'acrossai-database';

	/**
	 * Database abilities that modify data or schema.
	 *
	 * Everything not listed here is treated as a read, which is the safe default for this particular
	 * list: a read wrongly listed as a write is merely refused in read-only mode, while a write
	 * wrongly omitted would be permitted. So when adding an ability, err towards listing it.
	 *
	 * @since 0.0.45
	 * @var   string[]
	 */
	public const WRITES = array(
		'database/insert-db-row',
		'database/update-db-rows',
		'database/delete-db-rows',
		'database/search-replace',
		'database/optimize-db-tables',
		'database/convert-core-tables-to-innodb',
		'database/cleanup-expired-transients',
		'database/set-option-autoload',
	);

	/**
	 * Input keys that name a table, in priority order.
	 *
	 * Ten of the database abilities take one of these. The value may be a single name or a list, and
	 * may be a logical key (`posts`) or a physical name (`wp_posts`) — both are resolved before the
	 * denylist is consulted, because an operator denying `wp_posts` means the table, not the spelling.
	 *
	 * @since 0.0.45
	 * @var   string[]
	 */
	private const TABLE_KEYS = array( 'table', 'tables', 'table_name', 'table_keys' );

	/**
	 * Input keys carrying raw SQL.
	 *
	 * `run-db-select-query` and `explain-db-query` name their table inside the statement rather than
	 * in a parameter, so checking parameters alone left the denylist meaningless for exactly the
	 * ability most likely to be pointed at a sensitive table. Measured before this existed: with
	 * `users` permanently denied, `SELECT user_login, user_pass FROM wp_users` returned rows.
	 * Resolution is MySQL's — see {@see Db_Sql_Tables}.
	 *
	 * @since 0.0.45
	 * @var   string[]
	 */
	private const SQL_KEYS = array( 'sql', 'query' );

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Hook the wrapper.
	 *
	 * @since  0.0.45
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_register_ability_args', array( self::class, 'wrap' ), 20, 2 );
	}

	/**
	 * Wrap one ability's execute callback when it belongs to the database category.
	 *
	 * @since  0.0.45
	 * @param  mixed  $args Ability args.
	 * @param  string $name Ability name.
	 * @return mixed
	 */
	public static function wrap( $args, $name ) {
		if ( ! is_array( $args ) || self::CATEGORY !== ( $args['category'] ?? '' ) ) {
			return $args;
		}

		if ( ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		$inner = $args['execute_callback'];
		$name  = (string) $name;

		$args['execute_callback'] = static function ( $input = array() ) use ( $inner, $name ) {
			$input = is_array( $input ) ? $input : array();

			$refusal = self::check( $name, $input );

			if ( null !== $refusal ) {
				return $refusal;
			}

			$input   = self::apply_row_cap( $input );
			$restore = self::apply_time_cap();

			try {
				$result = $inner( $input );
			} finally {
				$restore();
			}

			return self::redact( self::cap_returned_rows( $result ) );
		};

		return $args;
	}

	/**
	 * The policy decision for one call, or null to proceed.
	 *
	 * Returns a `WP_Error`, not the suite's array envelope. The first version returned the envelope,
	 * reasoning that the database abilities already fail that way — and it was wrong in a way only
	 * running it showed: every ability declares its own `output_schema`, a refusal envelope satisfies
	 * none of them, and `WP_Ability::execute()` validates output and replaced the whole thing with a
	 * generic `ability_invalid_output`. The reason for the refusal was destroyed by the refusal.
	 *
	 * Core returns a `WP_Error` from the callback untouched, ahead of output validation
	 * (`class-wp-ability.php`: `if ( is_wp_error( $result ) ) { return $result; }`), so this is the
	 * channel that actually reaches the caller with `db_read_only` and its message intact.
	 *
	 * @since  0.0.45
	 * @param  string               $name  Ability name.
	 * @param  array<string, mixed> $input Caller input.
	 * @return \WP_Error|null
	 */
	public static function check( string $name, array $input ): ?\WP_Error {
		$enabled = Db_Policy_Guard::assert_enabled();

		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		if ( in_array( $name, self::WRITES, true ) ) {
			$writable = Db_Policy_Guard::assert_writable();

			if ( is_wp_error( $writable ) ) {
				return $writable;
			}
		}

		foreach ( array_merge( self::tables_in( $input ), self::tables_in_sql( $input ) ) as $table ) {
			$allowed = Db_Policy_Guard::assert_table( $table );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		return null;
	}

	/**
	 * Every table named in a call's input, as physical names.
	 *
	 * @since  0.0.45
	 * @param  array<string, mixed> $input Caller input.
	 * @return string[]
	 */
	private static function tables_in( array $input ): array {
		$found = array();

		foreach ( self::TABLE_KEYS as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$value = $input[ $key ];

			foreach ( is_array( $value ) ? $value : array( $value ) as $entry ) {
				if ( ! is_scalar( $entry ) ) {
					continue;
				}

				$entry = trim( (string) $entry );

				if ( '' === $entry ) {
					continue;
				}

				$found[] = self::to_physical( $entry );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Table names referenced by any raw SQL in the input.
	 *
	 * Delegates to {@see Db_Sql_Tables}, which asks MySQL to plan the statement and reads the tables
	 * out of its own rewritten form. The first version of this method was a regex over FROM/JOIN,
	 * which found the obvious cases and missed a CTE, a derived table and an aliased join — the
	 * shapes most likely to be reaching for a table the operator denied.
	 *
	 * @since  0.0.45
	 * @param  array<string, mixed> $input Caller input.
	 * @return string[]
	 */
	private static function tables_in_sql( array $input ): array {
		$found = array();

		foreach ( self::SQL_KEYS as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
				continue;
			}

			foreach ( Db_Sql_Tables::referenced( (string) $input[ $key ] ) as $name ) {
				$found[] = self::to_physical( $name );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Resolve a caller's table reference to a physical name.
	 *
	 * The database abilities take logical keys (`posts`, `options`) in some places and physical names
	 * in others. The denylist is written against physical names, so a logical key is resolved through
	 * the same allowlist the abilities use. Anything unrecognised is returned unchanged and checked
	 * as-is — an unknown name should still be matched against the denylist rather than waved through.
	 *
	 * @since  0.0.45
	 * @param  string $entry Caller's table reference.
	 * @return string
	 */
	private static function to_physical( string $entry ): string {
		if ( Database_Core_Table_Allowlist::is_valid_key( $entry ) ) {
			$physical = Database_Core_Table_Allowlist::resolve( $entry );

			if ( '' !== $physical ) {
				return $physical;
			}
		}

		return $entry;
	}


	/**
	 * Clamp a caller's row request to the configured ceiling.
	 *
	 * Applied to the INPUT rather than by rewriting SQL. Issue #293 covers why the existing
	 * `LIMIT`-append is unreliable — `/\bLIMIT\b/i` matches a `LIMIT` inside a subquery or a string
	 * literal, so no outer cap is added and the ceiling silently does not apply. Lowering the
	 * ability's own `limit` parameter uses the path the ability already honours.
	 *
	 * A caller asking for fewer rows keeps the smaller number; only a request above the ceiling
	 * moves.
	 *
	 * @since  0.0.45
	 * @param  array<string, mixed> $input Caller input.
	 * @return array<string, mixed>
	 */
	private static function apply_row_cap( array $input ): array {
		// Deliberately only tightens an existing parameter. The cap cannot be *injected* here:
		// the enforcer wraps every ability in the category, most of which declare
		// `additionalProperties: false` and no `limit` property, so adding the key would fail
		// their input validation rather than cap anything.
		//
		// That is why this is no longer the only place the cap is applied -- on its own it made
		// max_rows opt-in, binding only a caller who had already volunteered a number. See
		// self::cap_returned_rows().
		if ( ! array_key_exists( 'limit', $input ) ) {
			return $input;
		}

		$requested = is_numeric( $input['limit'] ) ? (int) $input['limit'] : null;
		$input['limit'] = Db_Policy_Guard::row_cap( $requested );

		return $input;
	}

	/**
	 * Enforce the row ceiling on what an ability actually returned.
	 *
	 * **Issue #293.** `apply_row_cap()` returns the input untouched when there is no `limit` key,
	 * which is the normal case -- an MCP caller asks for rows without naming a ceiling. The policy's
	 * `max_rows` therefore never entered the call, and the ability's own default applied instead.
	 * Measured on a live install with `max_rows: 2`:
	 *
	 *     SELECT ID FROM wp_posts LIMIT 10   -> 10 rows, truncated: false
	 *     SELECT ID FROM wp_posts            -> 63 rows, truncated: false
	 *
	 * A ceiling that binds only when the caller volunteers a number is not a ceiling. Capping the
	 * returned rows closes it for every ability in the category at once, whatever its input schema
	 * looks like.
	 *
	 * Schema-safe by construction: `rows` is only rewritten when it is already an array in the
	 * result, and `truncated` only when the ability already declares it. The enforcer must never add
	 * a key an ability's `output_schema` does not list -- with `additionalProperties: false` that
	 * fails validation and core replaces the whole response with `ability_invalid_output`, which is
	 * how an earlier version of this class destroyed its own refusal messages.
	 *
	 * @since  0.0.44
	 * @param  mixed $result Whatever the ability returned.
	 * @return mixed
	 */
	private static function cap_returned_rows( $result ) {
		$cap = Db_Policy_Guard::row_cap( null );

		if ( $cap < 1 || ! is_array( $result ) || ! isset( $result['rows'] ) || ! is_array( $result['rows'] ) ) {
			return $result;
		}

		if ( count( $result['rows'] ) <= $cap ) {
			return $result;
		}

		$result['rows'] = array_slice( $result['rows'], 0, $cap );

		if ( array_key_exists( 'row_count', $result ) ) {
			$result['row_count'] = count( $result['rows'] );
		}

		if ( array_key_exists( 'truncated', $result ) ) {
			$result['truncated'] = true;
		}

		return $result;
	}

	/**
	 * Apply the query time cap for the duration of one call, and hand back a restorer.
	 *
	 * `max_execution_time` is a MySQL session variable measured in milliseconds, and it applies to
	 * read-only SELECTs — which is exactly the runaway case worth stopping. Set per call and put
	 * back afterwards, so a governance setting never leaks into the rest of the request.
	 *
	 * Returns a no-op closure when there is no cap or no database, so the caller can always call it.
	 *
	 * @since  0.0.45
	 * @return callable
	 */
	private static function apply_time_cap(): callable {
		global $wpdb;

		$seconds = Db_Policy_Guard::time_cap();

		if ( $seconds < 1 || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return static function (): void {};
		}

		$suppressed = $wpdb->suppress_errors( true );
		$previous   = $wpdb->get_var( 'SELECT @@SESSION.max_execution_time' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a session variable, not data.
		$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', $seconds * 1000 ) );

		$wpdb->suppress_errors( $suppressed );

		return static function () use ( $wpdb, $previous ): void {
			$was = $wpdb->suppress_errors( true );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring the session variable.
			$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', is_numeric( $previous ) ? (int) $previous : 0 ) );

			$wpdb->suppress_errors( $was );
		};
	}

	/**
	 * Scrub secrets out of a result before it leaves the site.
	 *
	 * Reuses {@see Secret_Redactor}, so a credential is masked whether it was read out of a file or
	 * out of a table, and an operator configures the patterns once on the File Manager tab rather
	 * than twice.
	 *
	 * Works on the JSON encoding of the result and decodes it back. That is blunt, and deliberately
	 * so: a database row can hold a secret in any column at any depth, and walking the structure
	 * would mean guessing which of those columns matter. If the round trip cannot be completed the
	 * ORIGINAL result is returned rather than a half-scrubbed one — a partially redacted response
	 * that claims to be redacted is worse than an honest unredacted one.
	 *
	 * **Nothing is added to the payload.** An earlier version appended a redaction count, and every
	 * database ability's `output_schema` sets `additionalProperties: false`, so core replaced the whole
	 * response with `ability_invalid_output` — redaction broke every query it touched. The replacement
	 * token inside the values is the signal that redaction happened; a wrapper may not change a shape
	 * the ability declared.
	 *
	 * @since  0.0.45
	 * @param  mixed $result Ability result.
	 * @return mixed
	 */
	private static function redact( $result ) {
		if ( ! is_array( $result ) || ! Db_Policy_Guard::redacts_values() ) {
			return $result;
		}

		if ( ! class_exists( Secret_Redactor::class ) ) {
			return $result;
		}

		$encoded = wp_json_encode( $result );

		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return $result;
		}

		$scrubbed = Secret_Redactor::scrub( $encoded );

		if ( empty( $scrubbed['redacted'] ) ) {
			return $result;
		}

		$decoded = json_decode( (string) $scrubbed['text'], true );

		if ( ! is_array( $decoded ) ) {
			return $result;
		}

		return $decoded;
	}
}
