<?php
/**
 * BerlinDB Table subclass for the ActivityLog module (Feature 096).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ActivityLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Manages table creation and upgrades for the activity log.
 *
 * Carries the same phantom-version guard as CliAuthLog\Table: if the
 * db_version option exists but the physical table was dropped, BerlinDB's
 * needs_upgrade() returns false and skips install forever. Silent by design —
 * no error_log, no notice, no transient.
 */
class Table extends \BerlinDB\Database\Kern\Table {

	/**
	 * Physical table name (WITHOUT wpdb prefix).
	 *
	 * @var string
	 */
	protected $name = ActivityLogConstants::TABLE;

	/**
	 * Table schema version used to trigger maybe_upgrade().
	 *
	 * @var string
	 */
	protected $version = ActivityLogConstants::TABLE_VERSION;

	/**
	 * BerlinDB per-version upgrade callbacks.
	 *
	 * When a column is added or widened here, add BOTH a version bump and a
	 * paired callback — B34 records the failure mode when only one of the two
	 * happens: dbDelta compares names and not types, so a widened column
	 * silently keeps its old width, INSERT returns false, and the caller reads
	 * that as success.
	 *
	 * `1.1.0` re-keys four composite indexes to lead with `event_type`. The
	 * same trap applies to indexes and is in fact worse: dbDelta will not touch
	 * an index that already exists, and nothing anywhere compares an index's
	 * COLUMNS against the Schema, so a re-key without this callback is not
	 * merely ignored — it is undetectable. Queries keep using the old key and
	 * stay correct, just slower, which is why it would never be noticed.
	 *
	 * @var array<string, string>
	 */
	protected $upgrades = array(
		'1.1.0' => 'upgrade_to_1_1_0',
	);

	/**
	 * WordPress option key tracking the installed schema version.
	 *
	 * @var string
	 */
	protected $db_version_key = ActivityLogConstants::OPTION_DB_VERSION;

	/**
	 * Schema class for this table.
	 *
	 * @var string
	 */
	protected $schema = Schema::class;

	/**
	 * Use the per-site prefix ($wpdb->prefix), not the network base prefix.
	 *
	 * The log is per-site: a network-wide view is out of scope for 1.0.0, and
	 * a global table would make one site's operator able to read another's
	 * traffic.
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
	 * Create or upgrade the table, with the phantom-version guard.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		} elseif ( '' === (string) get_option( $this->db_version_key, '' ) ) {
			// The MIRROR of the phantom-version case above, and just as silent.
			//
			// Table present, version option gone. BerlinDB sees `exists()` and
			// takes the upgrade branch, but `get_pending_upgrades()` bails on an
			// empty `$db_version` — so it runs NO callbacks and then stamps the
			// current version anyway. The physical schema keeps whatever shape it
			// had while reporting itself up to date, and every later boot sees a
			// matching version and does nothing. A migration skipped this way is
			// unrecoverable without manual intervention.
			//
			// Seeding the oldest shipped version makes the chain run from the
			// start. Replaying an upgrade is safe — each callback here is
			// guarded and idempotent — whereas skipping one is not. B34.
			update_option( $this->db_version_key, '1.0.0' );
		}
		parent::maybe_upgrade();
	}

	/**
	 * 1.1.0 — re-key four composite indexes to lead with `event_type`.
	 *
	 * One table holds two populations (`request` and `ability`), so a key that
	 * omits the discriminator matches rows of both kinds. Measured on a copy of
	 * the live schema, filtering server + status examined 742 index entries
	 * under `(status, created_at)` and 239 under `(event_type, status,
	 * created_at)` for the identical result — 2.8x less work, because
	 * `ActivityLogController` puts `event_type` on every list query.
	 *
	 * Each pair is independent and separately guarded, so a partial failure
	 * leaves a coherent table and the unstamped version retries the rest.
	 *
	 * Returns false on the first failed statement. This migration DROPs, and
	 * `SchemaReconciler` reconciles columns only — it never looks at indexes at
	 * all — so the unstamped version is the ONLY retry this has. Returning true
	 * after a failure would advance the stamp and strand the old keys for good.
	 *
	 * @return bool True when every pair is in its 1.1.0 shape.
	 */
	protected function upgrade_to_1_1_0(): bool {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// old name => [ new name, new key columns ].
		$rekeys = array(
			'status_created'     => array( 'type_status_created', '`event_type`, `status`, `created_at`' ),
			'component_created'  => array( 'type_component_created', '`event_type`, `component_name`, `created_at`' ),
			'connection_created' => array( 'type_connection_created', '`event_type`, `connection_type`, `created_at`' ),
			'user_created'       => array( 'type_user_created', '`event_type`, `user_id`, `created_at`' ),
		);

		foreach ( $rekeys as $old_name => $new ) {
			list( $new_name, $columns ) = $new;

			// ADD first, then DROP. Either order leaves the table queryable,
			// but this one never has the window where neither key exists.
			if ( ! $this->index_exists( $new_name ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL over a plugin-owned table; every identifier is a hardcoded literal from the map above, never input. $wpdb->prepare() does not support DDL identifiers.
				$added = $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$new_name}` ({$columns})" );

				if ( false === $added ) {
					/** This action is documented in includes/Database/CliAuthLog/Table.php */
					do_action( 'acrossai_mcp_schema_upgrade_failed', ActivityLogConstants::TABLE, '1.1.0', $wpdb->last_error );

					return false;
				}
			}

			if ( $this->index_exists( $old_name ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- As above: hardcoded index name from the map, plugin-owned table.
				$dropped = $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$old_name}`" );

				if ( false === $dropped ) {
					/** This action is documented in includes/Database/CliAuthLog/Table.php */
					do_action( 'acrossai_mcp_schema_upgrade_failed', ActivityLogConstants::TABLE, '1.1.0', $wpdb->last_error );

					return false;
				}
			}
		}

		return true;
	}
}
