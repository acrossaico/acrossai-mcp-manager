<?php
/**
 * BerlinDB Table subclass for the OAuthTokens module (Feature 021).
 *
 * F011 phantom-version guard preserved. Sole storage for access + refresh
 * tokens (single table, `token_type` discriminator). SEC-021-001 family_id
 * column allows RFC 9700 §2.2.2 family-scoped revocation.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthTokens
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthTokens;

defined( 'ABSPATH' ) || exit;

class Table extends \BerlinDB\Database\Kern\Table {

	/** @var string */
	protected $name = 'acrossai_mcp_oauth_tokens';

	/** @var string */
	protected $version = '1.0.1';

	/**
	 * BerlinDB per-version upgrade callbacks.
	 *
	 * `1.0.1` (F032): adds `server_id` column (per FR-002) + composite KEY(server_id, client_id)
	 * per D28 3-part contract. Ordering per R2: this callback MUST fire BEFORE
	 * `OAuthClients\Table::upgrade_to_1_0_1()` so the JOIN backfill on Step 3 can still
	 * resolve `client_id → clients.server_id` before the client-side purge deletes source rows.
	 * Enforced by `Main::reconcile_database_schemas()` registration order.
	 *
	 * @var array<string, string>
	 */
	protected $upgrades = array(
		'1.0.1' => 'upgrade_to_1_0_1',
	);

	/** @var string */
	protected $db_version_key = 'acrossai_mcp_oauth_tokens_db_version';

	/** @var string */
	protected $schema = Schema::class;

	/** @var bool */
	protected $global = false;

	/** @var Table|null */
	protected static $instance = null;

	/**
	 * F032 (T011) — count of rows purged by the last successful run of
	 * upgrade_to_<v>()'s PURGE step. Read by OAuthClients\Table::upgrade_to_<v>()
	 * Step 6 to construct the aggregate acrossai_mcp_oauth_legacy_dcr_purged signal.
	 *
	 * Populated in upgrade_to_1_0_1() (US3 phase); defaults to 0 pre-migration.
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
	 * F032 (T011) — expose the last upgrade PURGE row count for aggregate signal fire.
	 *
	 * MUST be called only from OAuthClients\Table::upgrade_to_<v>()'s aggregate signal step.
	 * Do not use for any other purpose — the value is only defined after this Table's
	 * upgrade callback has run within the same request.
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
	 * 5-step callback per D28 3-part contract (mirrors F029 CliAuthLog + F030 MCPServer):
	 *   1. ADD COLUMN `server_id BIGINT UNSIGNED DEFAULT NULL` (idempotent via INFORMATION_SCHEMA)
	 *   2. ADD KEY `server_id_client_id` (idempotent via INFORMATION_SCHEMA.STATISTICS)
	 *   3. Backfill via JOIN on `oauth_clients.server_id` (idempotent — only touches NULL rows)
	 *   4. PURGE remaining `server_id IS NULL` rows (legacy DCR descendants); store count in $this->last_purge_count
	 *   5. MODIFY column to NOT NULL (idempotent via IS_NULLABLE = 'YES' check)
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_0_1(): bool {
		global $wpdb;

		$table         = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';
		$clients_table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		// Step 1 — ADD COLUMN (idempotent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-drift read; INFORMATION_SCHEMA has no caching layer.
		$col_exists = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
				DB_NAME,
				$table
			)
		);
		if ( empty( $col_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with plugin-owned table name + hardcoded column definition.
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `server_id` bigint(20) unsigned DEFAULT NULL" );
		}

		// Step 2 — ADD composite KEY (idempotent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$idx_exists = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'server_id_client_id'",
				DB_NAME,
				$table
			)
		);
		if ( empty( $idx_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `server_id_client_id` (`server_id`, `client_id`)" );
		}

		// Step 3 — Backfill via JOIN on clients.server_id (idempotent).
		// MUST run BEFORE OAuthClients callback's purge step so JOIN can still resolve source rows.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are plugin-owned ($wpdb->prefix + hardcoded slug); no user input is interpolated and $wpdb->prepare() cannot parameterise identifiers. Block-scoped because the interpolation sits inside a multi-line call, which a line-scoped phpcs:ignore does not reach.
		$wpdb->query(
			"UPDATE `{$table}` t
			 INNER JOIN `{$clients_table}` c ON t.client_id = c.client_id
			 SET t.server_id = c.server_id
			 WHERE t.server_id IS NULL AND c.server_id IS NOT NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Step 4 — PURGE remaining NULL rows (legacy DCR-descendant tokens).
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->last_purge_count = (int) $wpdb->query( "DELETE FROM `{$table}` WHERE server_id IS NULL" );

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

		return true;
	}
}
