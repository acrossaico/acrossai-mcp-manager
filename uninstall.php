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
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Preserve-by-default gate. Operators opt into destructive teardown by
// ticking the "Delete all data on uninstall" checkbox on the MCP tab.
if ( 1 !== (int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) ) {
	return;
}

global $wpdb;

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
