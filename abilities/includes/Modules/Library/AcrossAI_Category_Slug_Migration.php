<?php
/**
 * One-time migration: `acrossai-abilities-manager-*` category slugs → `acrossai-*`.
 *
 * The ability category slug is not merely a label. It is:
 *   1. the key of the `acrossai_library_config` site option, and
 *   2. the value stored in `{prefix}acrossai_abilities.category` for every
 *      DB-defined ability an operator created through the Abilities screen.
 *
 * Renaming the registered categories without touching those two stores would
 * be silently destructive. WordPress refuses to register an ability whose
 * category is not registered — `WP_Abilities_Registry::register()` calls
 * `_doing_it_wrong()` and returns null — so every operator-created ability
 * still pointing at an old slug would simply stop existing, visible only
 * under WP_DEBUG. The saved Integrations toggles would meanwhile revert to
 * their defaults, which for third-party integration cards means OFF.
 *
 * Both rewrites are plain string replacements and safe to repeat, but the
 * completion flag keeps them off the hot path once done.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Library
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites stored category slugs to the shortened `acrossai-` prefix.
 */
class AcrossAI_Category_Slug_Migration {

	/**
	 * Option flag recording that the rewrite has run.
	 *
	 * @var string
	 */
	public const DONE_OPTION = 'acrossai_category_slug_migration_done';

	/**
	 * Site option whose top-level keys this migration rewrites.
	 *
	 * Declared here rather than read from `AcrossAI_Ability_Library_Config::OPTION_KEY`: Feature 102
	 * deletes that class, and this one is retained. The option itself outlives it — the gate
	 * translation reads it once and then removes it — so the name is duplicated deliberately. Two
	 * literals are the correct shape when the class that used to own the name is going away.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const SOURCE_OPTION = 'acrossai_library_config';

	/**
	 * The prefix being retired.
	 *
	 * @var string
	 */
	public const OLD_PREFIX = 'acrossai-abilities-manager-';

	/**
	 * Its replacement.
	 *
	 * @var string
	 */
	public const NEW_PREFIX = 'acrossai-';

	/**
	 * Category slugs owned by this plugin.
	 *
	 * `toolset` is a no-op for the historical rename — it was introduced after
	 * it, so no site ever stored `acrossai-abilities-manager-toolset`. It is
	 * listed anyway so this stays a complete inventory of the categories this
	 * plugin registers, which is what `Test_Category_Slug_Migration` asserts
	 * and what any future rename would read.
	 *
	 * An explicit list, not a blanket prefix match. Three unrelated strings
	 * share the retired prefix — the `…-abilities` and `…-mcp-extension` asset
	 * handles and the `…-wrap` CSS class — and a regex sweep would rewrite
	 * them too. Anything not named here is left alone, including third-party
	 * categories that happen to be prefixed similarly.
	 *
	 * @var string[]
	 */
	private const OWNED = array(
		'acf',
		'admin-menu',
		'block',
		'cache',
		'comments',
		'contact-form-7',
		'content',
		'content-search',
		'core',
		'cron',
		'database',
		'debugging',
		'elementor',
		'file-manager',
		'fonts',
		'litespeed-cache',
		'loco-translate',
		'consent',
		'email',
		'store',
		'updraftplus',
		'all-in-one',
		'all-in-one-seo',
		'media',
		'menus',
		'options',
		'plugins',
		'rank-math',
		'recovery',
		'redirects',
		'settings',
		'site-health',
		'site-kit',
		'taxonomies',
		'themes',
		'toolset',
		'users',
		'widgets',
		'wpcode',
		'yoast-seo',
		'classic-editor',
		'events-calendar',
		'event-tickets',
		// Feature 136. Appended rather than inserted alphabetically, matching the three above:
		// the list is a migration input, so its order is history and reordering it would make the
		// diff unreadable for no gain.
		'versions',
	);

	/**
	 * Run the rewrite unless it has already completed.
	 *
	 * Safe to call on every admin request; the flag short-circuits it.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		self::migrate_library_config();
		self::migrate_db_abilities();

		update_option( self::DONE_OPTION, '1', false );
	}

	/**
	 * Rewrite the top-level keys of `acrossai_library_config`.
	 *
	 * Entries are keyed by category. An entry already using the new slug wins
	 * over a legacy one carrying the same category, so re-running cannot
	 * clobber a newer preference with a stale one.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private static function migrate_library_config(): void {
		$config = get_site_option( self::SOURCE_OPTION, null );

		if ( ! is_array( $config ) || array() === $config ) {
			return;
		}

		$migrated = array();
		$changed  = false;

		foreach ( $config as $category => $entry ) {
			$new_key = self::rename( (string) $category );

			if ( $new_key !== $category ) {
				$changed = true;
			}

			// A pre-existing entry under the new key takes precedence.
			if ( ! isset( $migrated[ $new_key ] ) ) {
				$migrated[ $new_key ] = $entry;
			}
		}

		if ( $changed ) {
			update_site_option( self::SOURCE_OPTION, $migrated );
		}
	}

	/**
	 * Rewrite the `category` column on operator-created abilities.
	 *
	 * Uses a direct query because this runs once, touches a plugin-owned
	 * table, and there is no query-layer method for a bulk column rewrite.
	 * Each owned slug is updated individually so the WHERE clause stays an
	 * exact match rather than a LIKE over an unbounded prefix.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private static function migrate_db_abilities(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$table = $wpdb->prefix . 'acrossai_abilities';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( self::OWNED as $slug ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET category = %s WHERE category = %s",
					self::NEW_PREFIX . $slug,
					self::OLD_PREFIX . $slug
				)
			);
		}
		// phpcs:enable

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Map one category slug to its new form, or return it unchanged.
	 *
	 * @since  0.0.34
	 * @param  string $category Stored category slug.
	 * @return string Renamed slug when owned, otherwise the input.
	 */
	private static function rename( string $category ): string {
		foreach ( self::OWNED as $slug ) {
			if ( self::OLD_PREFIX . $slug === $category ) {
				return self::NEW_PREFIX . $slug;
			}
		}

		return $category;
	}
}
