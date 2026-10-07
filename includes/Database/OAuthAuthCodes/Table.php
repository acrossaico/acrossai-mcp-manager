<?php
/**
 * BerlinDB Table subclass for the OAuthAuthCodes module (Feature 021).
 *
 * F011 phantom-version guard preserved.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAuthCodes
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes;

defined( 'ABSPATH' ) || exit;

class Table extends \BerlinDB\Database\Kern\Table {

	/** @var string */
	protected $name = 'acrossai_mcp_oauth_auth_codes';

	/** @var string */
	protected $version = '1.0.1';

	/**
	 * BerlinDB per-version upgrade callbacks.
	 *
	 * `1.0.1` (F032): adds `server_id` column (per FR-003) per D28 3-part contract.
	 * Ordering per R2: this callback MUST fire BEFORE OAuthClients so JOIN backfill
	 * can resolve source rows. Enforced by `Main::reconcile_database_schemas()` registration order.
	 *
	 * @var array<string, string>
	 */
	protected $upgrades = array(
		'1.0.1' => 'upgrade_to_1_0_1',
	);

	/** @var string */
	protected $db_version_key = 'acrossai_mcp_oauth_auth_codes_db_version';

	/** @var string */
	protected $schema = Schema::class;

	/** @var bool */
	protected $global = false;

	/** @var Table|null */
	protected static $instance = null;

	/**
	 * F032 (T012) — count of rows purged by the last successful run of
	 * upgrade_to_<v>()'s PURGE step. Read by OAuthClients\Table::upgrade_to_<v>()
	 * Step 6 to construct the aggregate acrossai_mcp_oauth_legacy_dcr_purged signal.
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
	 * F032 (T012) — expose the last upgrade PURGE row count for aggregate signal fire.
	 *
	 * MUST be called only from OAuthClients\Table::upgrade_to_<v>()'s aggregate signal step.
	 *
	 * @return int
	 * @internal
	 */
	public function get_last_purge_count(): int {
		return $this->last_purge_count;
	}

	/**
	 * Phantom-version guard (F011 SEC-011-002 preservation).
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
	 * 4-step callback per D28 (mirrors OAuthTokens\Table::upgrade_to_1_0_1 minus the KEY step,
	 * since auth_codes lookup pattern doesn't require the (server_id, client_id) composite):
	 *   1. ADD COLUMN `server_id BIGINT UNSIGNED DEFAULT NULL`
	 *   2. Backfill via JOIN on `oauth_clients.server_id`
	 *   3. PURGE remaining `server_id IS NULL` rows; store count in $this->last_purge_count
	 *   4. MODIFY column to NOT NULL
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_0_1(): bool {
		global $wpdb;

		$table         = $wpdb->prefix . 'acrossai_mcp_oauth_auth_codes';
		$clients_table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		// Step 1 — ADD COLUMN (idempotent).
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

		// Step 2 — Backfill via JOIN on clients.server_id (idempotent).
		// MUST run BEFORE OAuthClients callback's purge step (per R2).
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are plugin-owned ($wpdb->prefix + hardcoded slug); no user input is interpolated and $wpdb->prepare() cannot parameterise identifiers. Block-scoped because the interpolation sits inside a multi-line call, which a line-scoped phpcs:ignore does not reach.
		$wpdb->query(
			"UPDATE `{$table}` a
			 INNER JOIN `{$clients_table}` c ON a.client_id = c.client_id
			 SET a.server_id = c.server_id
			 WHERE a.server_id IS NULL AND c.server_id IS NOT NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Step 3 — PURGE remaining NULL rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->last_purge_count = (int) $wpdb->query( "DELETE FROM `{$table}` WHERE server_id IS NULL" );

		// Step 4 — MODIFY server_id to NOT NULL (idempotent via IS_NULLABLE check).
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

		return true;
	}
}
