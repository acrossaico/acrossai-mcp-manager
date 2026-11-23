<?php
/**
 * Feature 134 — the one place database access policy is stored and enforced.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Database access policy: one access mode, a table denylist that always wins, and resource caps.
 *
 * The file manager has had this shape since Feature 092 — an administrator sets the controls once on
 * a settings tab and every `file-manager/*` ability inherits them through
 * {@see Path_Allowlist_Guard}. The database abilities had real policy but all of it hardcoded: an
 * 18-key core-table allowlist, a column-name allowlist on writes, and nothing an operator could
 * adjust. This is the configurable half.
 *
 * **The state is read through `$wpdb`, not `get_option()`, and that is deliberate.** A policy switch
 * that `pre_option_*` can flip is not a policy switch: any code on the site could widen it while the
 * settings screen went on displaying the stored value, so what is enforced and what is shown would
 * differ with nothing to indicate it. There is no filter on the mode for the same reason. The
 * approach is borrowed from Novamira's read mode, which documents it as "so option filters and the
 * object cache cannot make it read as off".
 *
 * **Nothing here derives authorization from an ability's `readonly` annotation.** Annotations are
 * metadata any provider can set, including on an ability that writes. Read-only is decided by this
 * class and enforced by the database server.
 *
 * Scope, stated plainly: this protects a site from mistakes made by its administrator's own agent.
 * It is not a boundary against the administrator — every database ability already requires
 * `manage_options`, and somebody holding that can change these settings. The value is that a
 * careless or confused agent cannot exceed what was configured for it.
 *
 * @since 0.0.45
 */
final class Db_Policy_Guard {

	/**
	 * Option holding the whole policy, as one row.
	 *
	 * One row rather than six, because the policy is read on every database ability call and a
	 * single `$wpdb` round trip is the cheapest correct way to do that.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const OPTION = 'acrossai_abilities_db_policy';

	/**
	 * Full read and write access. The historical behaviour, and still the default.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const MODE_UNRESTRICTED = 'unrestricted';

	/**
	 * Reads allowed, writes refused. Reads additionally run inside a read-only transaction, so the
	 * server refuses a write however the statement is spelled.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const MODE_READ_ONLY = 'read-only';

	/**
	 * No database ability runs at all. For a site that wants the abilities registered — so an agent
	 * can see they exist and be told why they are off — without any access.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const MODE_OFF = 'off';

	/**
	 * `$wpdb` properties naming the tables that are never readable or writable.
	 *
	 * These hold password hashes, session tokens and password-reset keys. Returning them to an AI
	 * client is the worst outcome available from a read ability, so no configuration can permit it.
	 * Easy MCP AI's change log denies the same two.
	 *
	 * **Resolved through `$wpdb`, and matched EXACTLY.** An earlier version matched `_users` and
	 * `_usermeta` as suffixes, reasoning that over-denying was the safe direction. Running it
	 * disproved that: on this site it permanently locked `wp_mainwp_users` and
	 * `wp_acrossai_mcp_connector_approved_users` — a third-party table and one of our own — with no
	 * way for an operator to unlock either, because "always denied" is by definition not
	 * configurable. Over-denying is only safe when it is reversible.
	 *
	 * Going through `$wpdb` is also more correct than any pattern: it honours `CUSTOM_USER_TABLE`
	 * and `CUSTOM_USER_META_TABLE`, so a site that relocates its user tables is still covered, and
	 * on multisite both are global so there are no per-blog variants to match.
	 *
	 * An operator's own denylist keeps the loose suffix match — over-matching there is their
	 * decision and they can undo it.
	 *
	 * @since 0.0.45
	 * @var   string[]
	 */
	public const ALWAYS_DENIED_PROPS = array( 'users', 'usermeta' );

	/**
	 * Defaults. Unrestricted, because this ships into sites that already use these abilities and a
	 * silent tightening on upgrade would break them. The tab is how an operator opts into less.
	 *
	 * @since 0.0.45
	 * @var   array<string, mixed>
	 */
	private const DEFAULTS = array(
		'mode'            => self::MODE_UNRESTRICTED,
		'denied_tables'   => array(),
		'max_rows'        => 1000,
		'max_seconds'     => 15,
		'redact_values'   => true,
	);

	/**
	 * Per-request memo. The policy cannot change mid-request, and this is read on every call.
	 *
	 * @since 0.0.45
	 * @var   array<string, mixed>|null
	 */
	private static $memo = null;

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The current policy.
	 *
	 * @since  0.0.45
	 * @return array<string, mixed>
	 */
	public static function policy(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		self::$memo = self::sanitize( self::read_raw() );

		return self::$memo;
	}

	/**
	 * Replace the policy.
	 *
	 * @since  0.0.45
	 * @param  array<string, mixed> $policy Candidate policy.
	 * @return array<string, mixed> The sanitised policy as stored.
	 */
	public static function save( array $policy ): array {
		$clean = self::sanitize( $policy );

		update_option( self::OPTION, wp_json_encode( $clean ), false );

		self::$memo = $clean;

		return $clean;
	}

	/**
	 * Drop the per-request memo. For tests and for a save made outside this class.
	 *
	 * @since  0.0.45
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * The current access mode.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	public static function mode(): string {
		return (string) self::policy()['mode'];
	}

	/**
	 * Whether any database ability may run at all.
	 *
	 * @since  0.0.45
	 * @return true|WP_Error
	 */
	public static function assert_enabled() {
		if ( self::MODE_OFF !== self::mode() ) {
			return true;
		}

		return new WP_Error(
			'db_access_off',
			__( 'Database access is switched off for AI clients on this site. An administrator can change that under AcrossAI Settings → Database.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Whether a write may proceed.
	 *
	 * @since  0.0.45
	 * @return true|WP_Error
	 */
	public static function assert_writable() {
		$enabled = self::assert_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		if ( self::MODE_READ_ONLY !== self::mode() ) {
			return true;
		}

		return new WP_Error(
			'db_read_only',
			__( 'This site\'s database access is set to read-only, so nothing can be written. An administrator can change that under AcrossAI Settings → Database.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Whether one table may be touched.
	 *
	 * Takes the PHYSICAL table name, because that is what a caller ends up holding and what the
	 * denylist has to match. Checked on reads as well as writes: the point of denying `users` is that
	 * its contents never reach an AI client.
	 *
	 * @since  0.0.45
	 * @param  string $table Physical table name.
	 * @return true|WP_Error
	 */
	public static function assert_table( string $table ) {
		$table = trim( $table );

		if ( '' === $table ) {
			return new WP_Error( 'db_table_missing', __( 'No table was named.', 'acrossai-abilities-manager' ) );
		}

		foreach ( self::always_denied() as $locked ) {
			if ( strtolower( $table ) === strtolower( $locked ) ) {
				return new WP_Error(
					'db_table_denied',
					sprintf(
						/* translators: %s: table name */
						__( 'The table "%s" can never be read or written through these abilities: it holds password hashes, session tokens and reset keys. This is not configurable.', 'acrossai-abilities-manager' ),
						$table
					)
				);
			}
		}

		foreach ( (array) self::policy()['denied_tables'] as $suffix ) {
			if ( self::matches_suffix( $table, (string) $suffix ) ) {
				return new WP_Error(
					'db_table_denied',
					sprintf(
						/* translators: %s: table name */
						__( 'The table "%s" is on this site\'s database denylist. An administrator can change that under AcrossAI Settings → Database.', 'acrossai-abilities-manager' ),
						$table
					)
				);
			}
		}

		return true;
	}

	/**
	 * The row cap, clamped to the caller's own request.
	 *
	 * A caller asking for fewer rows gets fewer; a caller asking for more gets the policy ceiling.
	 *
	 * @since  0.0.45
	 * @param  int|null $requested What the caller asked for, if anything.
	 * @return int
	 */
	public static function row_cap( ?int $requested = null ): int {
		$ceiling = (int) self::policy()['max_rows'];

		if ( null === $requested || $requested < 1 ) {
			return $ceiling;
		}

		return min( $requested, $ceiling );
	}

	/**
	 * The query time cap in seconds. 0 means no cap.
	 *
	 * @since  0.0.45
	 * @return int
	 */
	public static function time_cap(): int {
		return (int) self::policy()['max_seconds'];
	}

	/**
	 * Whether returned values should pass through {@see Secret_Redactor}.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	public static function redacts_values(): bool {
		return (bool) self::policy()['redact_values'];
	}

	/**
	 * Every valid mode, for an input enum and for the settings UI.
	 *
	 * @since  0.0.45
	 * @return string[]
	 */
	public static function modes(): array {
		return array( self::MODE_UNRESTRICTED, self::MODE_READ_ONLY, self::MODE_OFF );
	}

	/**
	 * The physical names of the permanently-denied tables on this site.
	 *
	 * @since  0.0.45
	 * @return string[]
	 */
	public static function always_denied(): array {
		global $wpdb;

		$names  = array();
		$prefix = ( isset( $wpdb ) && is_object( $wpdb ) && is_string( $wpdb->prefix ?? null ) )
			? (string) $wpdb->prefix
			: 'wp_';

		foreach ( self::ALWAYS_DENIED_PROPS as $prop ) {
			if ( isset( $wpdb->{$prop} ) && is_string( $wpdb->{$prop} ) && '' !== $wpdb->{$prop} ) {
				$names[] = (string) $wpdb->{$prop};

				continue;
			}

			// FAIL CLOSED. `$wpdb` not telling us the table name must never mean "nothing is
			// denied" — an earlier version returned an empty list here, which silently dropped the
			// one protection in this class that is not meant to be configurable. A guessed name is
			// worth more than no name: the worst case is denying a table that does not exist.
			$names[] = $prefix . $prop;
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * The defaults, for the settings UI to offer a reset.
	 *
	 * @since  0.0.45
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Read the stored policy straight out of the options table.
	 *
	 * `$wpdb->get_var()` rather than `get_option()`, for the reason in the class docblock: no option
	 * filter and no object-cache entry sits between the stored policy and the enforced one. The cost
	 * is one query per request, paid once thanks to the memo.
	 *
	 * A missing row, unparseable JSON, or anything that is not an object all return the defaults —
	 * never a partially-understood policy, which could be more permissive than either the stored or
	 * the default one.
	 *
	 * @since  0.0.45
	 * @return array<string, mixed>
	 */
	private static function read_raw(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return self::DEFAULTS;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate: see the docblock.
		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION )
		);

		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::DEFAULTS;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : self::DEFAULTS;
	}

	/**
	 * Coerce a candidate policy into the stored shape.
	 *
	 * A whitelist by construction: the result is built key by key from the defaults, so nothing not
	 * named here can survive into storage or into an enforcement decision. An unrecognised mode
	 * becomes the SAFEST mode rather than the default one — a typo in a policy should not quietly
	 * widen access.
	 *
	 * @since  0.0.45
	 * @param  array<string, mixed> $policy Candidate.
	 * @return array<string, mixed>
	 */
	private static function sanitize( array $policy ): array {
		$clean = self::DEFAULTS;

		if ( isset( $policy['mode'] ) ) {
			$mode = is_string( $policy['mode'] ) ? strtolower( trim( $policy['mode'] ) ) : '';

			// An unrecognised mode is a configuration error, and the safe reading of one is "allow
			// less", not "allow what the defaults allow".
			$clean['mode'] = in_array( $mode, self::modes(), true ) ? $mode : self::MODE_READ_ONLY;
		}

		if ( isset( $policy['denied_tables'] ) ) {
			$clean['denied_tables'] = self::sanitize_table_list( $policy['denied_tables'] );
		}

		if ( isset( $policy['max_rows'] ) && is_numeric( $policy['max_rows'] ) ) {
			$clean['max_rows'] = max( 1, min( 10000, (int) $policy['max_rows'] ) );
		}

		if ( isset( $policy['max_seconds'] ) && is_numeric( $policy['max_seconds'] ) ) {
			$clean['max_seconds'] = max( 0, min( 300, (int) $policy['max_seconds'] ) );
		}

		if ( isset( $policy['redact_values'] ) ) {
			$clean['redact_values'] = (bool) $policy['redact_values'];
		}

		return $clean;
	}

	/**
	 * Clean a caller's table list.
	 *
	 * Entries are matched as suffixes, so only the characters a table name can legally contain are
	 * kept. A `%` or a backtick in a denylist entry would be a pattern or an injection attempt
	 * rather than a table name, and both are dropped rather than escaped.
	 *
	 * @since  0.0.45
	 * @param  mixed $list Candidate list.
	 * @return string[]
	 */
	private static function sanitize_table_list( $list ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}

		$clean = array();

		foreach ( $list as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}

			$entry = strtolower( trim( $entry ) );

			if ( '' === $entry || 1 !== preg_match( '/^[a-z0-9_]+$/', $entry ) ) {
				continue;
			}

			$clean[ $entry ] = $entry;
		}

		return array_values( $clean );
	}

	/**
	 * Whether a physical table name ends in one denylist entry.
	 *
	 * Suffix rather than exact, so one entry covers every table prefix and, on multisite, every
	 * site — `usermeta` matches `wp_usermeta` and `wp_7_usermeta` alike, which is the whole point.
	 *
	 * It is deliberately OVER-inclusive at the other end: a third-party table called
	 * `my_special_usermeta` also matches, because the `_usermeta` suffix is all this sees. That is
	 * the right way for the error to fall. A table named after `usermeta` most likely holds user
	 * data too, and the cost of denying one that does not is an operator renaming a denylist entry;
	 * the cost of the opposite is password hashes reaching an AI client. Matching is anchored on the
	 * `_` boundary so `meta` does not match `postmeta`.
	 *
	 * @since  0.0.45
	 * @param  string $table  Physical table name.
	 * @param  string $suffix Denylist entry.
	 * @return bool
	 */
	private static function matches_suffix( string $table, string $suffix ): bool {
		$table  = strtolower( $table );
		$suffix = strtolower( $suffix );

		if ( '' === $suffix ) {
			return false;
		}

		if ( $table === $suffix ) {
			return true;
		}

		return str_ends_with( $table, '_' . $suffix );
	}
}
