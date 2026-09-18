<?php
/**
 * BerlinDB Table subclass for the MCPServer module.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

/**
 * Manages database table creation and upgrades for the MCPServer module.
 *
 * Extends BerlinDB Kern Table (Feature 011 — supersedes the hand-rolled
 * dbDelta lifecycle documented in DECISIONS.md D9 + D7). Overrides
 * maybe_upgrade() with the phantom-version guard from
 * AcrossAI_Abilities_Table.php:96-101 — silent per Clarification Q1.
 */
class Table extends \BerlinDB\Database\Kern\Table {

	/**
	 * Physical table name (WITHOUT wpdb prefix).
	 *
	 * @var string
	 */
	protected $name = 'acrossai_mcp_servers';

	/**
	 * Table schema version used to trigger maybe_upgrade().
	 *
	 * `1.1.1` (F029): forces the paired `$upgrades` callback
	 * `upgrade_to_1_1_1()` to ADD the three F025 protocol-tool flag
	 * columns (`tool_discover_abilities`, `tool_get_ability_info`,
	 * `tool_execute_ability`) on installs where the columns declared in
	 * `Schema.php` never materialized because the F025 shipping bump
	 * landed without a version increment. BerlinDB's `upgrade()` path
	 * only runs registered `$upgrades` callbacks — it does NOT auto-diff
	 * the Schema against the live table — so a version bump WITHOUT a
	 * matching callback would silently stamp the new version without
	 * changing the physical schema.
	 *
	 * `1.1.2` (F030): forces the paired `upgrade_to_1_1_2()` callback to
	 * ADD the `override_abilities_permission` column. Follows the D28
	 * BerlinDB schema-drift-reconciliation contract byte-for-byte.
	 *
	 * `1.1.3` (F037): forces the paired `upgrade_to_1_1_3()` callback to
	 * ADD the `embeds_enabled` column (master gate for the per-server
	 * frontend embed shortcode + block output). Same D28 3-part contract.
	 *
	 * `1.1.4` (F037 redesign 2026-07-27): DROPs the `embeds_enabled`
	 * column + DROPs the `wp_acrossai_mcp_server_embed_transports`
	 * junction table. All F037 state migrated to the new
	 * `wp_acrossai_mcp_servers_meta` WP-canonical meta table.
	 *
	 * `1.1.5` (F082): forces the paired `upgrade_to_1_1_5()` callback to
	 * ADD the `abilities_default_policy` column. Follows the D28
	 * BerlinDB schema-drift-reconciliation contract byte-for-byte.
	 * Default `'per-ability'` preserves prior behaviour on every existing
	 * row — pre-F082 installs migrate to the row-only-wins-else-meta
	 * semantics they already had.
	 *
	 * `1.1.6` (F090): forces the paired `upgrade_to_1_1_6()` callback to ADD
	 * `server_type`, plus one targeted backfill. Same D28 3-part contract. It
	 * originally added `tools_default_policy` in the same pass; 1.1.7 retracted
	 * that column and the ADD has been removed from the callback, so the
	 * churn never reaches a fresh install.
	 *
	 * `server_type`'s column default `'mcp-adapter'` deliberately backfills
	 * every pre-existing row inside the ALTER — correct for all of them
	 * EXCEPT the F088 AcrossAI row, which the callback corrects with one
	 * slug-matched UPDATE. See the callback for why that UPDATE is
	 * conditional on having just created the column.
	 *
	 * `1.1.7` (F090 retraction): DROPs `tools_default_policy` again. The coarse
	 * 'expose'/'hide' rule it backed never reached `tools/call` —
	 * `MCP\ToolExposureGate` gates on curated rows alone — so an 'expose'
	 * server advertised tools it then refused. Removing the column is the fix.
	 * A forward migration rather than an amendment of 1.1.6 because 1.1.6 is
	 * already on `main`; same add-then-retract shape as 1.1.3 -> 1.1.4 for
	 * `embeds_enabled`. Only the TOOLS column goes —
	 * `abilities_default_policy` (1.1.5, F082) is a different feature and stays.
	 *
	 * @var string
	 */
	protected $version = '1.1.7';

	/**
	 * BerlinDB per-version upgrade callbacks. Runs when `db_version` in
	 * `wp_options` is less than the target version key.
	 *
	 * @var array<string, string>
	 */
	protected $upgrades = array(
		'1.1.1' => 'upgrade_to_1_1_1',
		'1.1.2' => 'upgrade_to_1_1_2',
		'1.1.3' => 'upgrade_to_1_1_3',
		'1.1.4' => 'upgrade_to_1_1_4',
		'1.1.5' => 'upgrade_to_1_1_5',
		'1.1.6' => 'upgrade_to_1_1_6',
		'1.1.7' => 'upgrade_to_1_1_7',
	);

	/**
	 * WordPress option key that tracks the installed schema version.
	 *
	 * @var string
	 */
	protected $db_version_key = 'acrossai_mcp_servers_db_version';

	/**
	 * Schema class for this table.
	 *
	 * @var string
	 */
	protected $schema = Schema::class;

	/**
	 * Use per-site prefix ($wpdb->prefix), not the network base prefix.
	 *
	 * @var bool
	 */
	protected $global = false;

	/**
	 * Singleton instance.
	 *
	 * @var Table|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Table
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create or upgrade the table with the phantom-version guard.
	 *
	 * If the db_version_key option exists but the physical table was manually
	 * dropped, BerlinDB's needs_upgrade() would return false and skip install.
	 * Clearing the option first forces a fresh install on the next run.
	 * SILENT per Clarification Q1 — no error_log, no admin notice, no transient.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}
		parent::maybe_upgrade();
	}

	/**
	 * BerlinDB upgrade callback for 1.1.0 → 1.1.1 (F029).
	 *
	 * Adds three F025 protocol-tool flag columns to `wp_acrossai_mcp_servers`
	 * on installs where the F025 shipping bump landed without a version
	 * increment, so the columns declared in `Schema.php` never materialized.
	 * Fresh installs after F029 pick up the columns via `create()` (install
	 * path) since they're in the Schema.
	 *
	 * Idempotent per-column via `INFORMATION_SCHEMA.COLUMNS` existence check;
	 * only ADDs columns that are actually missing. Safe to re-run on
	 * already-correct installs (each per-column check short-circuits).
	 *
	 * Returns `true` on success (BerlinDB stamps the version), `false` on
	 * failure (BerlinDB aborts and leaves the version unstamped so the
	 * upgrade retries on the next admin request).
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_1_1(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		$targets = array(
			'tool_discover_abilities' => 'ADD COLUMN `tool_discover_abilities` tinyint(1) NOT NULL DEFAULT 1',
			'tool_get_ability_info'   => 'ADD COLUMN `tool_get_ability_info` tinyint(1) NOT NULL DEFAULT 1',
			'tool_execute_ability'    => 'ADD COLUMN `tool_execute_ability` tinyint(1) NOT NULL DEFAULT 1',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-drift read; INFORMATION_SCHEMA has no caching layer.
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME IN ('tool_discover_abilities', 'tool_get_ability_info', 'tool_execute_ability')",
				DB_NAME,
				$table
			)
		);

		$existing_map = is_array( $existing ) ? array_flip( $existing ) : array();

		foreach ( $targets as $column_name => $ddl ) {
			if ( isset( $existing_map[ $column_name ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with plugin-owned table name ($wpdb->prefix + hardcoded slug) + hardcoded column definitions; idempotent via existence check above. $wpdb->prepare() does not support DDL identifiers.
			$wpdb->query( "ALTER TABLE `{$table}` " . $ddl );
		}

		return true;
	}

	/**
	 * BerlinDB upgrade callback for 1.1.1 → 1.1.2 (F030).
	 *
	 * Adds the `override_abilities_permission tinyint(1) NOT NULL DEFAULT 0`
	 * column to `wp_acrossai_mcp_servers`. Default 0 preserves prior behaviour
	 * on every existing row.
	 *
	 * Idempotent via `INFORMATION_SCHEMA.COLUMNS` existence check. Mirrors
	 * `upgrade_to_1_1_1()` shape verbatim per D28 3-part contract.
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_1_2(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-drift read; INFORMATION_SCHEMA has no caching layer.
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = 'override_abilities_permission'",
				DB_NAME,
				$table
			)
		);

		if ( ! empty( $existing ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with plugin-owned table name + hardcoded column definition; idempotent via existence check above. $wpdb->prepare() does not support DDL identifiers.
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `override_abilities_permission` tinyint(1) NOT NULL DEFAULT 0" );

		return true;
	}

	/**
	 * F037 upgrade — bump to 1.1.3.
	 *
	 * Adds the `embeds_enabled tinyint(1) unsigned NOT NULL DEFAULT 0`
	 * column to `wp_acrossai_mcp_servers`. Default 0 preserves prior
	 * behaviour on every existing row (fresh install ships with zero
	 * shortcode output for every server per FR-002).
	 *
	 * Idempotent via `INFORMATION_SCHEMA.COLUMNS` existence check. Mirrors
	 * `upgrade_to_1_1_2()` shape verbatim per D28 3-part contract.
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_1_3(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-drift read; INFORMATION_SCHEMA has no caching layer.
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = 'embeds_enabled'",
				DB_NAME,
				$table
			)
		);

		if ( ! empty( $existing ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with plugin-owned table name + hardcoded column definition; idempotent via existence check above. $wpdb->prepare() does not support DDL identifiers.
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `embeds_enabled` tinyint(1) unsigned NOT NULL DEFAULT 0" );

		return true;
	}

	/**
	 * F037 redesign upgrade — bump to 1.1.4.
	 *
	 * DROPs the `embeds_enabled` column added by upgrade_to_1_1_3 +
	 * DROPs the retired `wp_acrossai_mcp_server_embed_transports`
	 * junction table. All F037 state migrated to the new
	 * `wp_acrossai_mcp_servers_meta` meta table under `_embeds_enabled`
	 * + `_embed_dto:*` meta_keys. Safe to lose the old rows: this is
	 * F037 dev-branch data (feature not shipped to `main` yet); admin
	 * re-selects post-upgrade.
	 *
	 * Idempotent via `INFORMATION_SCHEMA` existence checks.
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_1_4(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// Step 1 — DROP embeds_enabled column (idempotent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$col_exists = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = 'embeds_enabled'",
				DB_NAME,
				$table
			)
		);
		if ( ! empty( $col_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `embeds_enabled`" );
		}

		// Step 2 — DROP the retired junction table (idempotent).
		$junction = $wpdb->prefix . 'acrossai_mcp_server_embed_transports';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$junction}`" );
		// Also clear the stale db_version option so a reinstall doesn't
		// think the table still needs upgrading.
		delete_option( 'acrossai_mcp_server_embed_transports_db_version' );

		return true;
	}

	/**
	 * F082 upgrade — bump to 1.1.5.
	 *
	 * Adds the `abilities_default_policy varchar(16) NOT NULL DEFAULT 'per-ability'`
	 * column to `wp_acrossai_mcp_servers`. Default `'per-ability'` preserves
	 * prior behaviour on every existing row — no ability's effective exposure
	 * changes on upgrade because pre-F082 servers already used the row-only +
	 * meta.mcp.public fallback that `'per-ability'` policy encodes.
	 *
	 * Idempotent via `INFORMATION_SCHEMA.COLUMNS` existence check. Mirrors
	 * `upgrade_to_1_1_2()` shape verbatim per D28 3-part contract.
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_1_5(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-drift read; INFORMATION_SCHEMA has no caching layer.
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = 'abilities_default_policy'",
				DB_NAME,
				$table
			)
		);

		if ( ! empty( $existing ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with plugin-owned table name + hardcoded column definition; idempotent via existence check above. $wpdb->prepare() does not support DDL identifiers.
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `abilities_default_policy` varchar(16) NOT NULL DEFAULT 'per-ability'" );

		return true;
	}

	/**
	 * Feature 090 — ADD `server_type` and correct the one row the column default
	 * gets wrong. (It also ADDed `tools_default_policy`; 1.1.7 retracted that
	 * column and this callback no longer creates it — see below.)
	 *
	 * Each step is independently idempotent, so a partially applied upgrade
	 * (fatal between statements, hosting timeout) heals on the next run rather
	 * than erroring or double-applying.
	 *
	 * Uses BerlinDB's INHERITED PUBLIC `column_exists()` rather than the
	 * INFORMATION_SCHEMA query the 1.1.1-1.1.5 callbacks each hand-rolled. The
	 * base class has provided it since v3; a `private` re-implementation here is
	 * a fatal `Access level ... must be public` clash with the parent that
	 * NEITHER PHPCS NOR PHPSTAN CATCHES — it surfaces only as a white screen on
	 * a real page load.
	 *
	 * @return bool True on success; BerlinDB stamps the version.
	 */
	protected function upgrade_to_1_1_6(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// `server_type`. The column default backfills every existing row to
		// 'mcp-adapter' as part of the ALTER — the correct legacy value for all
		// of them, so there is no backfill pass.
		$added_server_type = false;
		if ( ! $this->column_exists( 'server_type' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with plugin-owned table name + hardcoded column definition; idempotent via the existence check above. $wpdb->prepare() does not support DDL identifiers.
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `server_type` varchar(32) NOT NULL DEFAULT 'mcp-adapter'" );
			$added_server_type = true;
		}

		// `tools_default_policy` was ADDed here until 1.1.7 retracted it. The ADD
		// is REMOVED rather than left to be undone one version later, so no
		// install ever creates a column the next migration immediately deletes.
		//
		// Not merely tidiness. Leaving it made a 1.1.5 rewind run ADD then DROP,
		// and DDL implicitly COMMITs (B53) — which committed the deliberate
		// `server_type` UPDATE in this file's own T012 test, so `tear_down()`'s
		// restore was rolled back and the NEXT test's precondition failed. A
		// migration that does no DDL when there is nothing to change keeps the
		// per-test rollback intact.
		//
		// Installs that already ran the original 1.1.6 still have the column;
		// `upgrade_to_1_1_7()` is what removes it for them.

		// Correct the one row the column default gets wrong. The F088 AcrossAI
		// row is an AcrossAI-type server, but the ALTER just stamped it
		// 'mcp-adapter' along with everything else.
		//
		// It cannot be fixed by the seeder: F090 puts `server_type` in that
		// row's `initial` bucket (so the operator can switch type as the escape
		// hatch when the sibling is deactivated), and `initial` only writes at
		// INSERT — the row already exists on any site that has F088.
		//
		// GATED on having just created the column, deliberately. An
		// unconditional UPDATE would re-run on a later invocation and revert an
		// operator who had switched this server to 'mcp-adapter', silently
		// undoing the very escape hatch the ownership change exists to provide.
		if ( $added_server_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'server_type' => 'acrossai' ),
				array( 'server_slug' => DefaultServerSeeder::ACROSSAI_SLUG ),
				array( '%s' ),
				array( '%s' )
			);
		}

		return true;
	}

	/**
	 * Feature 090 retraction — DROP `tools_default_policy`.
	 *
	 * 1.1.6 added it one version earlier. It is dropped rather than left in
	 * place because a column Schema.php no longer declares is exactly the drift
	 * B34 describes: the live table and the schema disagree, and the next
	 * reader cannot tell which one is wrong.
	 *
	 * No data migration accompanies the DROP. Every row still held the default
	 * `'per-tool'`, which is precisely the behaviour that survives — so nothing
	 * has to be materialised into curated rows to keep a server serving what it
	 * served. A site that HAD set 'expose' or 'hide' falls back to its curated
	 * set, which is the honest answer once the rule is gone.
	 *
	 * Idempotent via the existence check, so a partially applied upgrade heals
	 * on the next run. Uses BerlinDB's INHERITED PUBLIC `column_exists()` — see
	 * `upgrade_to_1_1_6()` for why re-implementing it privately is fatal.
	 *
	 * @return bool True on success; BerlinDB stamps the version.
	 */
	protected function upgrade_to_1_1_7(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		if ( $this->column_exists( 'tools_default_policy' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with a plugin-owned table name + hardcoded column name; idempotent via the existence check above. $wpdb->prepare() does not support DDL identifiers.
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `tools_default_policy`" );
		}

		return true;
	}
}
