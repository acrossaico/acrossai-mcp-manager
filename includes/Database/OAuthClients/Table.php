<?php
/**
 * BerlinDB Table subclass for the OAuthClients module (Feature 021).
 *
 * F011 phantom-version guard preserved — silent per Clarification Q1.
 * DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION: extend via leading-\ FQN.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthClients
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthClients;

defined( 'ABSPATH' ) || exit;

class Table extends \BerlinDB\Database\Kern\Table {

	/** @var string */
	protected $name = 'acrossai_mcp_oauth_clients';

	/** @var string */
	protected $version = '1.0.1';

	/**
	 * BerlinDB per-version upgrade callbacks.
	 *
	 * `1.0.1` (F032): adds `server_id` column + composite UNIQUE(client_id, server_id) per FR-001/FR-004.
	 * Also purges legacy DCR rows (per FR-007, Q3 clarification) + fires aggregate observability signal.
	 * ⚠️ MUST-BE-PAIRED-WITH T024 registration-order fix (per SEC-032-T-001) — this callback reads
	 * runtime state from `OAuthTokens\Table::instance()->get_last_purge_count()` +
	 * `OAuthAuthCodes\Table::instance()->get_last_purge_count()` which requires those callbacks to
	 * have run FIRST.
	 *
	 * @var array<string, string>
	 */
	protected $upgrades = array(
		'1.0.1' => 'upgrade_to_1_0_1',
	);

	/** @var string */
	protected $db_version_key = 'acrossai_mcp_oauth_clients_db_version';

	/** @var string */
	protected $schema = Schema::class;

	/** @var bool Per-site prefix (multisite-safe). */
	protected $global = false;

	/** @var Table|null */
	protected static $instance = null;

	/**
	 * F032 — count of legacy DCR rows purged by upgrade_to_1_0_1() Step 3.
	 * Read by Main::reconcile_database_schemas() coordinator for aggregate signal.
	 *
	 * @var int
	 */
	protected int $last_purge_count = 0;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * F032 — expose the last upgrade PURGE row count for the Main coordinator's
	 * aggregate acrossai_mcp_oauth_legacy_dcr_purged signal.
	 *
	 * @return int
	 * @internal
	 */
	public function get_last_purge_count(): int {
		return $this->last_purge_count;
	}

	/**
	 * Phantom-version guard (F011 SEC-011-002 preservation).
	 *
	 * If the version option exists but the physical table was manually
	 * dropped, BerlinDB's needs_upgrade() would skip the CREATE. Clear the
	 * option so the fresh install fires. SILENT per Q1.
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}
		parent::maybe_upgrade();
	}

	/**
	 * F032 BerlinDB upgrade callback for 1.0.0 → 1.0.1.
	 *
	 * 6-step callback per D28 3-part contract. **⚠️ MUST run AFTER OAuthTokens + OAuthAuthCodes
	 * callbacks** (registration order enforced by `Main::reconcile_database_schemas()`) so Step 6's
	 * aggregate signal reads populated purge counts from sibling Tables:
	 *
	 *   1. ADD COLUMN `server_id BIGINT UNSIGNED DEFAULT NULL` (idempotent)
	 *   2. Backfill admin clients from `server-{id}-` prefix WITH orphan-server guard
	 *      (per FR-005 amendment / SEC-032-003 remediation) — parsed server_id MUST exist in
	 *      wp_acrossai_mcp_servers; otherwise row is left NULL for Step 3 to purge
	 *   3. PURGE `server_id IS NULL` rows (legacy DCR + phantom-server admin rows)
	 *   4. Swap indexes: ADD composite UNIQUE(client_id, server_id) → DROP standalone UNIQUE(client_id)
	 *      (order matters: table is never unconstrained mid-swap)
	 *   5. MODIFY server_id to NOT NULL (idempotent via IS_NULLABLE check)
	 *   6. Fire aggregate `acrossai_mcp_oauth_legacy_dcr_purged` signal iff any of the three
	 *      purge counts > 0 (per FR-024 / D19 fail-open observability)
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_0_1(): bool {
		global $wpdb;

		$table         = $wpdb->prefix . 'acrossai_mcp_oauth_clients';
		$servers_table = $wpdb->prefix . 'acrossai_mcp_servers';

		// Step 1 — ADD COLUMN as NULL-allowed (idempotent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$col_exists = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
				DB_NAME,
				$table
			)
		);
		if ( empty( $col_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `server_id` bigint(20) unsigned DEFAULT NULL" );
		}

		// Step 2 — Backfill admin clients from `server-{id}-` prefix WITH orphan-server guard.
		// Per SEC-032-003 remediation: parsed server_id MUST exist in oauth_servers.
		// Rows whose parsed prefix points at a deleted/non-existent server row are LEFT NULL
		// and correctly PURGED in Step 3 alongside legacy DCR rows.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are plugin-owned ($wpdb->prefix + hardcoded slug); no user input is interpolated and $wpdb->prepare() cannot parameterise identifiers. Block-scoped because the interpolation sits inside a multi-line call, which a line-scoped phpcs:ignore does not reach.
		$wpdb->query(
			"UPDATE `{$table}`
			 SET server_id = CAST( SUBSTRING_INDEX( SUBSTRING_INDEX( client_id, '-', 2 ), '-', -1 ) AS UNSIGNED )
			 WHERE server_id IS NULL
			   AND client_id LIKE 'server-%'
			   AND CAST( SUBSTRING_INDEX( SUBSTRING_INDEX( client_id, '-', 2 ), '-', -1 ) AS UNSIGNED )
			       IN ( SELECT id FROM `{$servers_table}` )"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Step 3 — PURGE remaining NULL rows (legacy DCR + phantom-server admins).
		// Per Q3 clarification (A-aggressive form). Live AI-host sessions bound to purged rows
		// will disconnect on next request; users re-authorize via standard OAuth flow (FR-025 README warning).
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->last_purge_count = (int) $wpdb->query( "DELETE FROM `{$table}` WHERE server_id IS NULL" );

		// Step 4 — Swap UNIQUE(client_id) → UNIQUE(client_id, server_id).
		// Order matters: ADD composite FIRST so table is never unconstrained mid-swap.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$composite_exists = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'client_id_server_id'",
				DB_NAME,
				$table
			)
		);
		if ( empty( $composite_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `client_id_server_id` (`client_id`, `server_id`)" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$legacy_exists = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'client_id'",
				DB_NAME,
				$table
			)
		);
		if ( ! empty( $legacy_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `client_id`" );
		}

		// Step 5 — MODIFY server_id to NOT NULL (idempotent via IS_NULLABLE check).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$is_nullable = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
				DB_NAME,
				$table
			)
		);
		if ( 'YES' === $is_nullable ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` MODIFY `server_id` bigint(20) unsigned NOT NULL" );
		}

		// Step 6 — REMOVED (moved to Main::reconcile_database_schemas() coordinator per 2026-07-21 fix).
		// Original R2 ordering had OAuthClients running LAST so it could read
		// get_last_purge_count() from sibling Tables. That ordering triggered
		// "Unknown column 'c.server_id'" runtime failure because the JOIN backfill in
		// sibling Tables ran BEFORE OAuthClients added the column. Reordered — OAuthClients
		// runs FIRST — and aggregate signal moved to Main so it fires after all 3 tables complete.

		return true;
	}
}
