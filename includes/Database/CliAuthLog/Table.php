<?php
/**
 * BerlinDB Table subclass for the CliAuthLog module.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

defined( 'ABSPATH' ) || exit;

/**
 * Manages database table creation and upgrades for the CliAuthLog module.
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
	protected $name = 'acrossai_mcp_cli_auth_logs';

	/**
	 * Table schema version used to trigger maybe_upgrade().
	 *
	 * `1.0.1` (F029): forces reconciliation of three type-mismatched columns
	 * (`status`, `failure_code`, `app_password_uuid`) that drifted from
	 * `Schema.php` on some installs. dbDelta compares column names only, not
	 * types, so BerlinDB's install path (`create()`) never notices the
	 * width drift on existing installs. The paired `$upgrades` callback
	 * `upgrade_to_1_0_1()` below runs explicit `ALTER TABLE MODIFY COLUMN`
	 * on the drifted columns; per-column idempotency via `INFORMATION_SCHEMA`.
	 *
	 * @var string
	 */
	protected $version = '1.0.1';

	/**
	 * BerlinDB per-version upgrade callbacks. Runs when `db_version` in
	 * `wp_options` is less than the target version key.
	 *
	 * @var array<string, string>
	 */
	protected $upgrades = array(
		'1.0.1' => 'upgrade_to_1_0_1',
	);

	/**
	 * WordPress option key that tracks the installed schema version.
	 *
	 * @var string
	 */
	protected $db_version_key = 'acrossai_mcp_cli_auth_logs_db_version';

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
	 * BerlinDB upgrade callback for 1.0.0 → 1.0.1 (F029).
	 *
	 * Reconciles three columns whose live DB widths drifted from Schema.php
	 * on some installs. BerlinDB's `create()` (install path) never notices
	 * width drift because it only fires on tables that don't yet exist —
	 * and dbDelta (which older BerlinDB versions used for the upgrade path)
	 * only compares column names, not types.
	 *
	 * Idempotent per-column via INFORMATION_SCHEMA width comparison; only
	 * ALTERs columns whose live width differs from the Schema.php target.
	 * Safe to re-run on already-correct installs (each per-column check
	 * short-circuits) or if a future upgrade cycle re-fires this callback.
	 *
	 * Returns `true` on success (BerlinDB stamps the version), `false` on
	 * failure (BerlinDB aborts and leaves the version unstamped so the
	 * upgrade retries on the next admin request).
	 *
	 * As of F091 the code actually honours that contract — it previously
	 * returned `true` unconditionally, so a failed statement still advanced the
	 * stamp and stranded the width permanently. These are MODIFY statements and
	 * `SchemaReconciler` deliberately never performs those, so an unstamped
	 * version is the only retry they have.
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_0_1(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_cli_auth_logs';

		$targets = array(
			'status'            => array(
				'length' => 32,
				'ddl'    => "MODIFY COLUMN `status` varchar(32) NOT NULL DEFAULT 'pending'",
			),
			'failure_code'      => array(
				'length' => 64,
				'ddl'    => "MODIFY COLUMN `failure_code` varchar(64) NOT NULL DEFAULT ''",
			),
			'app_password_uuid' => array(
				'length' => 36,
				'ddl'    => "MODIFY COLUMN `app_password_uuid` varchar(36) NOT NULL DEFAULT ''",
			),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-drift read; INFORMATION_SCHEMA has no caching layer.
		$current = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME IN ('status', 'failure_code', 'app_password_uuid')",
				DB_NAME,
				$table
			),
			OBJECT_K
		);

		if ( ! is_array( $current ) ) {
			return false;
		}

		$ok = true;

		foreach ( $targets as $column_name => $spec ) {
			if ( ! isset( $current[ $column_name ] ) ) {
				continue;
			}
			if ( (int) $current[ $column_name ]->CHARACTER_MAXIMUM_LENGTH === $spec['length'] ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with plugin-owned table name ($wpdb->prefix + hardcoded slug) + hardcoded column definitions; idempotent via width check above. $wpdb->prepare() does not support DDL identifiers.
			$result = $wpdb->query( "ALTER TABLE `{$table}` " . $spec['ddl'] );

			// F091 — report failure honestly. These are MODIFY statements, which
			// SchemaReconciler deliberately never performs (widening is safe but
			// narrowing truncates, and it cannot tell which a Schema intends), so
			// the unstamped version is this migration's only retry. Every target
			// is attempted before returning, so one failing column does not hide
			// the state of the others.
			if ( false === $result ) {
				$ok = false;

				/**
				 * Fires when a schema migration's statement failed.
				 *
				 * The version is deliberately left unstamped when this fires, so
				 * the migration retries on the next administrative page load. On
				 * a persistently failing site — no ALTER privilege, say — a
				 * subscriber will therefore see this repeatedly. That is the
				 * intended signal that operator intervention is required, not a
				 * bug (D19 fail-open observability).
				 *
				 * @since 0.3.7 (F091)
				 *
				 * @param string $table   Un-prefixed table stem.
				 * @param string $version Schema version whose callback failed.
				 * @param string $error   Database error as reported by $wpdb.
				 */
				do_action( 'acrossai_mcp_schema_upgrade_failed', 'acrossai_mcp_cli_auth_logs', '1.0.1', $wpdb->last_error );
			}
		}

		return $ok;
	}
}
