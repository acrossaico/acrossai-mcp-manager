<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Behavior (Feature 012 — preserve-by-default):
 *
 *   Reads the `acrossai_mcp_uninstall_delete_data` option (int 0/1, default 0).
 *   - 0 (default): preserves ALL plugin data on uninstall — no tables dropped,
 *     no options deleted, no scheduled hooks cleared. This matches the WP.org
 *     plugin guideline #5 (uninstall must not destroy data unless the operator
 *     explicitly opts in). This is a BEHAVIOR CHANGE from pre-Feature-012, where
 *     `acrossai_mcp_oauth_tokens` + `acrossai_mcp_oauth_audit` were dropped
 *     unconditionally.
 *   - 1 (destructive): drops every plugin-owned wp_acrossai_mcp_* table
 *     (including the orphaned pre-F040 OAuth tables — see F083 note below),
 *     deletes every `acrossai_mcp_*` option via LIKE-sweep, and clears the
 *     OAuth cleanup cron.
 *     Operators opt in via the "Delete all data on uninstall" checkbox on the
 *     MCP tab of the shared AcrossAI Settings page (see
 *     admin/Partials/SettingsMenu.php).
 *
 * F106 absorbed the separate acrossai-abilities-manager plugin's tree into
 * this one (abilities/{includes,admin,public}/). Its tables and options are
 * now this plugin's responsibility too, torn down below under a SEPARATE
 * gate — `acrossai_abilities_uninstall_delete_data` — rather than folded
 * into the gate above. Before the merge these were two independently
 * distributed plugins, each deleted (and uninstalled) on its own schedule,
 * each with its own "Delete all data on uninstall" checkbox (the Abilities
 * one lives on the "Abilities" tab, registered by
 * abilities/admin/Partials/SettingsMenu.php). A merge that quietly combined
 * the two flags would change what a single checkbox does out from under any
 * operator who already ticked one but not the other — exactly the kind of
 * surprise a preserve-by-default uninstall exists to avoid. So the two
 * gates stay independent even though one file now acts on both: ticking
 * only the MCP box still preserves Abilities data, and vice versa, matching
 * pre-merge behaviour exactly.
 *
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ============================================================================
// This plugin's own data (Feature 012). Preserve-by-default gate — operators
// opt into destructive teardown by ticking the "Delete all data on
// uninstall" checkbox on the MCP tab. Deliberately an `if` block rather than
// the early `return` this file used before F106: the absorbed Abilities half
// below has its own independent gate, and an early return here would skip it
// whenever the MCP box is left unchecked.
// ============================================================================
if ( 1 === (int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) ) {

	// Drop all four plugin tables. Table names are derived from $wpdb->prefix +
	// hardcoded stems (no user input reaches SQL). Uses the `%i` identifier
	// placeholder (WordPress 6.2+) so $wpdb->prepare() escapes the table name
	// safely and no phpcs:ignore is needed.
	// Feature 015 — Access Control v2 (FR-012 / FR-013). Purge the plugin's
	// namespace via the vendor RuleQuery BEFORE the raw DROP so BerlinDB
	// invalidates its cache for the namespace. class_exists guards against the
	// "vendor package uninstalled before this plugin" edge case (US5 scenario 3).
	if ( class_exists( '\WPBoilerplate\AccessControl\Database\Rule\RuleQuery' ) ) {
		$rule_query = new \WPBoilerplate\AccessControl\Database\Rule\RuleQuery( 'mcp' );
		if ( method_exists( $rule_query, 'purge_namespace' ) ) {
			$rule_query->purge_namespace( 'acrossai-mcp-manager' );
		}
	}

	// Feature 095 took OAuth ownership back from the companion plugin. The four
	// `acrossai_mcp_oauth_*` / `acrossai_mcp_connector_approved_users` tables are
	// now THIS plugin's own, created by includes/Database/OAuth{Clients,Tokens,
	// AuthCodes}/ and ConnectorApprovedUsers/, so dropping them here is simply
	// correct uninstall behaviour.
	//
	// DO NOT REMOVE THESE FOUR ENTRIES. They were originally added by F083 as a
	// safety net for orphans left behind by the F040 split, and that justification
	// is now obsolete — but the entries themselves are not. Reading the old
	// rationale and concluding they are stale would silently leak four tables on
	// uninstall, which is exactly the B44 failure mode (adding a BerlinDB Table
	// subclass does not automatically add its table here, and nothing fails to
	// compile if it is missing).
	//
	// What F095 DID remove is the separate one-shot sweeper that dropped these
	// same names during normal admin_init operation. That was a live hazard: a
	// freshly created, still-empty table on a site that had not yet run the sweep
	// was precisely its target. uninstall.php runs only at uninstall, so it was
	// never part of that hazard.
	//
	// The companion's OAuth stack has now been stripped (acrossaico/acrossai-pro#113),
	// so it owns no `acrossai_mcp_connector_%` options and the LIKE-sweep exclusion
	// that protected them is gone — see the note on the sweep below.
	$tables = array(
		$wpdb->prefix . 'acrossai_mcp_servers',
		$wpdb->prefix . 'acrossai_mcp_cli_auth_logs',
		$wpdb->prefix . 'mcp_access_control',            // F015 AC rule table (TABLE_SLUG = 'mcp').
		$wpdb->prefix . 'acrossai_mcp_server_abilities', // F017 per-server ability overrides.
		$wpdb->prefix . 'acrossai_mcp_server_tools',     // F020 per-server tool selection.
		$wpdb->prefix . 'acrossai_mcp_servers_meta',     // F037 MCPServerMeta — per-server key/value settings (Embeds tab, etc).
		$wpdb->prefix . 'acrossai_mcp_activity_log',     // F096 activity log — MCP requests + ability runs.
		$wpdb->prefix . 'acrossai_mcp_clients',          // F104 clients registry — one row per client per server.
		// F095 — this plugin's own OAuth tables. See the DO NOT REMOVE note above.
		$wpdb->prefix . 'acrossai_mcp_oauth_clients',
		$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
		$wpdb->prefix . 'acrossai_mcp_oauth_auth_codes',
		$wpdb->prefix . 'acrossai_mcp_connector_approved_users',
	);
	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	// Feature 015 — delete the vendor-owned schema version option. The
	// `acrossai_mcp_*` LIKE-sweep below does NOT match `wpb_ac_mcp_*`, so
	// the vendor's version tracking option must be cleaned up explicitly.
	delete_option( 'wpb_ac_mcp_db_version' );

	// Delete every `acrossai_mcp_*` option.
	//
	// This sweep used to exclude `acrossai_mcp_connector_%` because F040 had
	// handed those options to the companion plugin. F095 took the connector stack
	// back, and the companion stopped registering any of it, so the exclusion no
	// longer protects a foreign namespace — it orphans our own rows. Measured on a
	// dev install carrying both plugins: the only option the exclusion still
	// matched was `acrossai_mcp_connector_approved_users_db_version`, the version
	// key for a table THIS plugin creates (see the DROP list above). Leaving it
	// behind is the B44 failure mode — a table's companion option surviving an
	// uninstall that dropped the table.
	$options = $wpdb->get_col(
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			'acrossai_mcp_%'
		)
	);
	if ( is_array( $options ) ) {
		foreach ( $options as $option_name ) {
			delete_option( $option_name );
		}
	}
} // End acrossai_mcp_uninstall_delete_data gate.

// ============================================================================
// F106 — the absorbed Abilities Manager half's own data. Separate gate from
// the one above — see the file docblock for why. Operators opt in via the
// "Delete all data on uninstall" checkbox on the Abilities tab (see
// abilities/admin/Partials/SettingsMenu.php::render_uninstall_field()).
//
// Ported from the standalone plugin's own uninstall.php (acrossai-abilities-
// manager, still present on disk under wp-content/plugins/ as of this
// writing) rather than re-derived, so this is a transplant of already-proven
// logic, not a guess at what the absorbed half owns.
// ============================================================================
if ( 1 === (int) get_option( 'acrossai_abilities_uninstall_delete_data', 0 ) ) {

	// The unified abilities table (Feature 017+). Table name and
	// `db_version_key` come straight from AcrossAI_Abilities_Table — its own
	// docblock says "Must match what uninstall.php deletes."
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'acrossai_abilities' ) );
	delete_option( 'acrossai_abilities_db_version' );

	// `{prefix}abilities_access_control` — the per-consumer access-control
	// table from wpboilerplate/wpb-access-control (TABLE_SLUG = 'abilities' on
	// AcrossAI_Abilities_Access_Control) — is DELIBERATELY NOT dropped here.
	//
	// Unlike `mcp_access_control` above (TABLE_SLUG = 'mcp', used nowhere else
	// on the filesystem), the 'abilities' slug is not exclusive to code that
	// lives inside this plugin. The standalone acrossai-abilities-manager
	// plugin — the exact plugin F106 absorbed — ships the identical
	// AcrossAI_Abilities_Access_Control class with the identical TABLE_SLUG,
	// and as of this writing that plugin is STILL PRESENT on disk (deactivated
	// but not deleted) on at least one real install. The access-control
	// package enforces no cross-plugin slug registry — "pick a slug that
	// doesn't collide" is a convention in its own docblock, not something it
	// checks — so a site that still has the standalone plugin's files sitting
	// around (mid-migration, or simply never cleaned up) and later deletes
	// THIS plugin would have both codebases constructing
	// `new RuleTable( 'abilities' )` against the exact same table.
	//
	// Dropping it here would risk destroying rules a sibling installation of
	// the very code this plugin absorbed still depends on — the SEC-001
	// failure mode this repo already treats as unacceptable elsewhere (see
	// F039's history on the sibling `mcp_access_control` table). Preserving
	// it is the safe default; an operator who has confirmed no other
	// consumer of the 'abilities' slug exists on their install can still drop
	// it by hand. Do not "fix" this by adding it to the drop list — re-derive
	// the ownership question first, because the standalone plugin reusing
	// this exact slug is not a corner case, it is the documented absorption
	// this feature performed.
	//
	// `wpb_ac_abilities_db_version` (the schema-version option for that
	// table) is left alone for the same reason — it is the companion table's
	// own bookkeeping, not an orphan of a table this uninstall drops.

	// Legacy Logger table (Feature 006 → removed in Feature 040). The
	// absorbed activator no longer creates this table on activation, but
	// installs that upgraded through that history may still carry it and its
	// schema-version option. Drop both on opt-in uninstall, same as the
	// standalone plugin always did.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'acrossai_ability_logs' ) );
	delete_option( 'acrossai_ability_logs_db_version' );

	// Action Scheduler cleanup — the retired Logger scheduled a recurring
	// cleanup action on this hook. Drain any pending queue entries. Guarded —
	// safe when Action Scheduler is inactive.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'acrossai_ability_logger_cleanup' );
	}

	// Per-site settings options the absorbed half owns.
	delete_option( 'acrossai_abilities_log_retention_days' );
	delete_option( 'acrossai_abilities_uninstall_delete_data' );
	delete_option( 'acrossai_abilities_per_page' );

	// Feature 046 — absorbed Core Settings option (extra upload MIME types).
	delete_option( 'acrossai_abilities_manager_extra_mimes' );

	// Feature 088 / 095 — ability- and plugin-suggestion kill-switches.
	delete_option( 'acrossai_disable_plugin_suggestions' );
	delete_option( 'acrossai_disable_ability_suggestions' );

	// AcrossAI_Ability_Override_Processor::DEFAULT_CAPABILITY_OPTION — the
	// permission-floor override. Never written by this codebase (read-only
	// via a filter default), but it is still this plugin's own option key and
	// a full uninstall should not leave an operator's override behind.
	delete_option( 'acrossai_default_ability_capability' );

	// Feature 092 — File Manager path allowlists + secret redactor options.
	delete_option( 'acrossai_file_manager_write_allowlist' );
	delete_option( 'acrossai_file_manager_read_allowlist' );
	delete_option( 'acrossai_file_manager_redaction_config' );

	// Feature 093 — Content Filters (hardening) options.
	delete_option( 'acrossai_file_manager_dangerous_extensions' );
	delete_option( 'acrossai_file_manager_block_double_extensions' );
	delete_option( 'acrossai_file_manager_htaccess_directive_scan' );
	delete_option( 'acrossai_file_manager_sanitize_filename_check' );
	delete_option( 'acrossai_file_manager_write_max_bytes' );
	delete_option( 'acrossai_file_manager_sensitive_read_denylist' );
	delete_option( 'acrossai_file_manager_strict_filename_filter' );
	delete_option( 'acrossai_file_manager_mime_type_check' );

	// Feature 094 — Backup & Audit toggle options.
	delete_option( 'acrossai_file_manager_backup_enabled' );
	delete_option( 'acrossai_file_manager_backup_retention_days' );
	delete_option( 'acrossai_file_manager_audit_log_enabled' );
	delete_option( 'acrossai_file_manager_audit_log_retention_days' );

	// Claim options stamped by the absorbed activator's one-time option
	// migrations (AcrossAI_Activator::activate(), run on activation AND, via
	// Main::adopt_abilities_schemas(), on every plugin update). Each is a
	// per-site regular option; leaving one behind after its table/feature is
	// gone is the same B44 failure mode the MCP half's `wpb_ac_mcp_db_version`
	// note above warns against.
	delete_option( 'acrossai_category_slug_migration_done' ); // AcrossAI_Category_Slug_Migration::DONE_OPTION.
	delete_option( 'acrossai_library_gate_migration_done' );  // AcrossAI_Library_Gate_Migration::DONE_OPTION.
	delete_option( 'acrossai_global_styles_flag_migration_done' ); // Global_Styles_Flag_Migration::DONE_OPTION.

	// Network-wide (site) options. `get_site_option()`/`update_site_option()`
	// fall back to the regular options table on a non-multisite install, so
	// `delete_site_option()` is correct in both cases and is what the
	// standalone plugin's own uninstall.php already used for the Library
	// config below.
	delete_site_option( 'acrossai_library_config' );                  // AcrossAI_Category_Slug_Migration::SOURCE_OPTION / Library_Gate_Migration::SOURCE_OPTION.
	delete_site_option( 'acrossai_integrations' );                    // AcrossAI_Integration_Settings::OPTION_KEY / Library_Gate_Migration::INTEGRATIONS_OPTION.
	delete_site_option( 'acrossai_integration_seeded_slugs' );        // AcrossAI_Integration_Default_Opt_Ins::SEEDED_OPTION.
	delete_site_option( 'acrossai_integration_default_opt_ins_done' ); // AcrossAI_Integration_Default_Opt_Ins::DONE_OPTION.

	// Feature 094 — recursively delete the on-disk backup + log dirs. They
	// live under wp-content/ with Deny-from-all .htaccess guards inside. Uses
	// native PHP filesystem here, same as the standalone plugin's own
	// uninstall.php: uninstall.php runs BEFORE the plugin autoloader, so
	// WP_Filesystem_Init isn't reachable.
	$acrossai_abilities_dirs_to_purge = array(
		WP_CONTENT_DIR . '/acrossai-file-manager-backups',
		WP_CONTENT_DIR . '/acrossai-file-manager-logs',
	);
	foreach ( $acrossai_abilities_dirs_to_purge as $acrossai_abilities_dir ) {
		if ( ! is_dir( $acrossai_abilities_dir ) ) {
			continue;
		}
		$acrossai_abilities_iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $acrossai_abilities_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $acrossai_abilities_iter as $acrossai_abilities_path ) {
			if ( $acrossai_abilities_path->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is not available during uninstall; @ suppresses expected "not empty" races.
				@rmdir( $acrossai_abilities_path->getPathname() );
			} else {
				wp_delete_file( $acrossai_abilities_path->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is not available during uninstall; @ suppresses expected "not empty" races.
		@rmdir( $acrossai_abilities_dir );
	}
}
