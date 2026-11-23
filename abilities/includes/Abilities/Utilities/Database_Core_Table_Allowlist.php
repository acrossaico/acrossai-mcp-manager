<?php
/**
 * Feature 086 — logical → physical name allowlist for WordPress core tables.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.32
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed allowlist of 18 core WordPress table keys. Callers pass a logical
 * key (e.g. "posts", "options") and this class resolves it to the current
 * site's physical name via the corresponding $wpdb property. Prevents
 * arbitrary identifier injection at the schema level — no ability ever
 * accepts a raw physical table name from the caller.
 *
 * The 10 blog-scoped keys map to `$wpdb->{key}` (which honours the
 * current-blog prefix on multisite). The 8 network-global keys map to
 * network-wide tables that are shared across all sites on multisite.
 */
class Database_Core_Table_Allowlist {

	private const BLOG_KEYS = array(
		'posts'        => 'posts',
		'postmeta'     => 'postmeta',
		'comments'     => 'comments',
		'commentmeta'  => 'commentmeta',
		'terms'        => 'terms',
		'termmeta'     => 'termmeta',
		'term_taxonomy' => 'term_taxonomy',
		'term_relationships' => 'term_relationships',
		'links'        => 'links',
		'options'      => 'options',
	);

	private const NETWORK_KEYS = array(
		'users'          => 'users',
		'usermeta'       => 'usermeta',
		'blogs'          => 'blogs',
		'blogmeta'       => 'blogmeta',
		'site'           => 'site',
		'sitemeta'       => 'sitemeta',
		'signups'        => 'signups',
		'registration_log' => 'registration_log',
	);

	/**
	 * Every valid logical table key.
	 *
	 * @return string[]
	 */
	public static function all_keys(): array {
		return array_merge( array_keys( self::BLOG_KEYS ), array_keys( self::NETWORK_KEYS ) );
	}

	/**
	 * Return true if the given logical key is in the allowlist.
	 *
	 * @param string $key Logical table key.
	 * @return bool
	 */
	public static function is_valid_key( string $key ): bool {
		return isset( self::BLOG_KEYS[ $key ] ) || isset( self::NETWORK_KEYS[ $key ] );
	}

	/**
	 * Return true if the key is network-global (shared across all sites on multisite).
	 *
	 * @param string $key Logical table key.
	 * @return bool
	 */
	public static function is_network_key( string $key ): bool {
		return isset( self::NETWORK_KEYS[ $key ] );
	}

	/**
	 * Resolve a logical key to the current site's physical table name via $wpdb.
	 * Returns an empty string if the key is unknown or the property is missing.
	 *
	 * @param string $key Logical table key.
	 * @return string
	 */
	public static function resolve( string $key ): string {
		if ( ! self::is_valid_key( $key ) ) {
			return '';
		}
		global $wpdb;
		if ( ! isset( $wpdb->{$key} ) || ! is_string( $wpdb->{$key} ) ) {
			return '';
		}
		return (string) $wpdb->{$key};
	}

	/**
	 * Validate a list of input keys. Returns [ valid_keys, invalid_keys ].
	 * Deduplicates and preserves input order for valid entries.
	 *
	 * @param mixed $raw Raw input (array of strings expected).
	 * @return array{0: string[], 1: string[]}
	 */
	public static function partition( $raw ): array {
		$valid   = array();
		$invalid = array();
		$seen    = array();
		if ( ! is_array( $raw ) ) {
			return array( $valid, $invalid );
		}
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$key = sanitize_key( $item );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( self::is_valid_key( $key ) ) {
				$valid[] = $key;
			} else {
				$invalid[] = $key;
			}
		}
		return array( $valid, $invalid );
	}
}
