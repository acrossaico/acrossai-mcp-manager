<?php
/**
 * F083 — one-shot cleanup of orphaned pre-F040 OAuth tables on plugin update.
 *
 * Older builds of this plugin created four OAuth-era tables. Feature 040
 * moved the OAuth subsystem to the companion plugin (acrossai-pro), which
 * creates its own FRESH tables under the `acrossai_pro_mcp_*` namespace and
 * never reads, migrates, renames, or drops the old names — leaving the old
 * tables abandoned in place with no owner on any install that ran a
 * pre-F040 build.
 *
 * This service runs from `Main::reconcile_database_schemas()` (admin_init@3,
 * the same D28 code path that reconciles BerlinDB schema drift after an
 * in-place plugin update), so the cleanup fires on the first admin page load
 * after updating — no reactivation needed. The orphans cannot ride BerlinDB's
 * own `$upgrades` mechanism because their Table classes no longer exist in
 * this plugin.
 *
 * Safety model:
 *   - One-shot: gated on the `acrossai_mcp_legacy_oauth_cleanup_done` option
 *     (one cheap autoloaded-No option read per admin request after that).
 *   - A table is dropped ONLY when it exists AND is empty. A non-empty
 *     legacy table is NEVER auto-dropped — pro never migrated data, so rows
 *     in an old table exist nowhere else; destroying them is an operator
 *     decision (README `= Unreleased =` recipe), not an upgrade side effect.
 *   - Skipped non-empty tables fire `acrossai_mcp_legacy_oauth_cleanup_skipped`
 *     (D19 fail-open observability) and are logged; the done-flag still sets
 *     so the check does not re-run every request. Operators can re-trigger by
 *     deleting the option.
 *   - Table names are $wpdb->prefix + hardcoded stems; identifiers go through
 *     the `%i` placeholder (WP 6.2+; plugin requires 6.9+). No user input
 *     reaches SQL.
 *   - Cannot touch the companion's live data: acrossai-pro's tables and
 *     version options all live under the disjoint `acrossai_pro_mcp_*`
 *     namespace (verified against its codebase). The legacy `*_db_version`
 *     options deleted here are exact-match keys created by pre-F040 builds
 *     of THIS plugin.
 *
 * A11 pure-service exception (stateless, static-only — no singleton, no ctor).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database
 * @since      0.3.3 (F083)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot dropper for the orphaned pre-F040 OAuth tables.
 *
 * @since 0.3.3 (F083)
 */
final class LegacyOAuthCleanup {

	/**
	 * Option flag marking the cleanup as done. Deleted rows re-trigger it.
	 */
	public const DONE_OPTION = 'acrossai_mcp_legacy_oauth_cleanup_done';

	/**
	 * Prefix-less stems of the four orphaned tables. Each also names its
	 * legacy version-tracker option as `{stem}_db_version`.
	 *
	 * @var string[]
	 */
	public const LEGACY_TABLE_STEMS = array(
		'acrossai_mcp_oauth_clients',
		'acrossai_mcp_oauth_tokens',
		'acrossai_mcp_oauth_auth_codes',
		'acrossai_mcp_connector_approved_users',
	);

	/**
	 * Run the one-shot cleanup if it has not run yet.
	 *
	 * For each legacy table: absent → just clear its stale version option;
	 * present + empty → DROP + clear its version option; present + non-empty
	 * → leave untouched, collect for the skipped-action payload.
	 *
	 * @since 0.3.3 (F083)
	 * @return void
	 */
	public static function maybe_cleanup(): void {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		global $wpdb;

		$skipped = array();
		foreach ( self::LEGACY_TABLE_STEMS as $stem ) {
			$table = $wpdb->prefix . $stem;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence probe; INFORMATION_SCHEMA has no caching layer.
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$table
				)
			);

			if ( 0 === $exists ) {
				// Table already gone — clear any stale version option and move on.
				delete_option( $stem . '_db_version' );
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot emptiness probe on an orphaned table.
			$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

			if ( $rows > 0 ) {
				// Pro never migrated data — these rows exist nowhere else.
				// Never auto-destroy them; leave the drop to the operator
				// (README recipe) and surface the skip.
				$skipped[ $stem ] = $rows;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Idempotent drop of a verified-empty orphaned table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			delete_option( $stem . '_db_version' );
		}

		if ( ! empty( $skipped ) ) {
			/**
			 * Fires when the F083 legacy-OAuth cleanup skipped non-empty
			 * orphaned tables (D19 observability). The cleanup will NOT
			 * retry automatically — operators either run the README recipe
			 * or delete the `acrossai_mcp_legacy_oauth_cleanup_done` option
			 * to re-trigger after emptying the tables.
			 *
			 * @since 0.3.3 (F083)
			 *
			 * @param array<string, int> $skipped Map of prefix-less table stem => row count.
			 */
			do_action( 'acrossai_mcp_legacy_oauth_cleanup_skipped', $skipped );
		}

		update_option( self::DONE_OPTION, 1, false );
	}
}
