<?php
/**
 * Feature 082 — regression coverage for the D28 3-part contract on the
 * `abilities_default_policy` column added to `wp_acrossai_mcp_servers`
 * at Table version 1.1.4 → 1.1.5.
 *
 * `WP_UnitTestCase` runs `Activator::activate()` (which calls
 * `MCPServerTable::instance()->maybe_upgrade()`), so by the time this test
 * runs the schema is already at v1.1.5 with the column present. This test
 * also exercises the drop-and-restore path to prove the `INFORMATION_SCHEMA`
 * idempotency guard fires correctly on the ALTER (B34 mitigation).
 *
 * Mirrors `PermissionOverrideColumnUpgradeTest` byte-for-byte — F082's
 * migration follows the same D28 contract as F030's.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class PolicyReconcilerTest extends WP_UnitTestCase {

	/**
	 * Schema self-heal. The drop-and-restore test below issues DDL, and DDL
	 * implicitly COMMITs — it escapes WP_UnitTestCase's per-test transaction
	 * rollback. If that test ever fails mid-flight, the dropped column and
	 * rewound version option would otherwise persist and poison every later
	 * test (and every later RUN — the test DB survives between runs). This
	 * tearDown guarantees the column + version option are restored no matter
	 * how the test exited.
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'abilities_default_policy'" );
		if ( empty( $rows ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `abilities_default_policy` varchar(16) NOT NULL DEFAULT 'per-ability'" );
		}
		update_option( 'acrossai_mcp_servers_db_version', '1.1.5' );

		parent::tear_down();
	}

	public function test_abilities_default_policy_column_exists_after_upgrade(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'abilities_default_policy'" );

		$this->assertCount( 1, $rows, 'F082 must ship the abilities_default_policy column.' );
		$this->assertSame( 'varchar(16)', strtolower( (string) $rows[0]->Type ) );
		$this->assertSame( 'NO', (string) $rows[0]->Null );
		$this->assertSame( 'per-ability', (string) $rows[0]->Default );
	}

	public function test_new_column_defaults_to_per_ability_for_freshly_inserted_rows(): void {
		// Unique slug — DDL in the drop-and-restore test COMMITs, so rows from
		// aborted earlier runs can survive rollback and collide on a fixed slug.
		$slug      = 'f082-upgrade-test-' . uniqid();
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'F082 upgrade test server',
				'server_slug'            => $slug,
				'description'            => 'Seeded by PolicyReconcilerTest',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
		$this->assertGreaterThan( 0, $server_id, 'add_item must insert the seed row.' );
		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		$this->assertNotEmpty( $rows, 'Freshly inserted server row must be queryable.' );
		$row = $rows[0];

		// Default preserves pre-F082 behaviour on every existing row.
		$this->assertSame( 'per-ability', (string) $row->abilities_default_policy );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'acrossai_mcp_servers',
			array( 'id' => $server_id ),
			array( '%d' )
		);
	}

	public function test_upgrade_to_1_1_5_is_idempotent_when_column_already_exists(): void {
		// The bootstrapped state already has the column. Force a version
		// downgrade in wp_options and re-invoke maybe_upgrade; the callback
		// MUST short-circuit via INFORMATION_SCHEMA existence check and NOT
		// re-issue the ALTER (which would produce a duplicate-column error).
		update_option( 'acrossai_mcp_servers_db_version', '1.1.4' );

		$this->expectNotToPerformAssertions();
		MCPServerTable::instance()->maybe_upgrade();
		MCPServerTable::instance()->maybe_upgrade();
	}

	public function test_upgrade_to_1_1_5_recreates_dropped_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// Drop the column AND rewind the version option — this is the drift
		// scenario B34 documents (silent write-loss when schema drifts).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `abilities_default_policy`" );
		$this->rerun_upgrades_from( '1.1.4' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'abilities_default_policy'" );
		$this->assertCount( 1, $rows, 'D28 upgrade path must re-add the dropped column.' );

		$this->assertSame( '1.1.5', (string) get_option( 'acrossai_mcp_servers_db_version' ) );
	}

	public function test_upgrades_array_registers_1_1_5_entry(): void {
		// Verify T005 wired the callback in the $upgrades array. Reflection
		// is used because $upgrades is protected in BerlinDB Table.
		$table = MCPServerTable::instance();
		$refl  = new \ReflectionClass( $table );
		$prop  = $refl->getProperty( 'upgrades' );
		$prop->setAccessible( true );
		$upgrades = $prop->getValue( $table );

		$this->assertArrayHasKey( '1.1.5', $upgrades, 'F082 T005 must register the 1.1.5 upgrade entry.' );
		$this->assertSame( 'upgrade_to_1_1_5', $upgrades['1.1.5'], 'F082 T005 must name the callback upgrade_to_1_1_5.' );
	}

	public function test_version_bump_is_strict_semver_forward_from_1_1_4(): void {
		// D28 requires the $version bump to be strict semver forward.
		$table = MCPServerTable::instance();
		$refl  = new \ReflectionClass( $table );
		$prop  = $refl->getProperty( 'version' );
		$prop->setAccessible( true );
		$version = (string) $prop->getValue( $table );

		$this->assertSame(
			1,
			version_compare( $version, '1.1.4', '>' ) ? 1 : 0,
			"F082 \$version ({$version}) must be strictly greater than F037's 1.1.4."
		);
	}

	/**
	 * Rewind the stored schema version and re-run the upgrade path.
	 *
	 * BerlinDB v3 guards maybe_upgrade() with a 900-second `*_upgrade_lock`
	 * transient meant for production concurrency. A test that deliberately
	 * rewinds the version to re-exercise an upgrade has to clear it, otherwise
	 * maybe_upgrade() bails silently and the dropped column is never restored —
	 * which then breaks every later test in the suite, because DDL implicitly
	 * COMMITs and escapes WP_UnitTestCase's rollback.
	 *
	 * @param string $rewind_to Version to rewind the option to.
	 */
	private function rerun_upgrades_from( string $rewind_to ): void {
		update_option( 'acrossai_mcp_servers_db_version', $rewind_to );
		delete_transient( 'acrossai_mcp_servers_db_version_upgrade_lock' );

		$table = MCPServerTable::instance();
		$table->needs_upgrade();   // Refreshes the cached db_version from the option.

		$this->assertNotEmpty(
			$table->get_pending_upgrades(),
			"Precondition: rewinding to {$rewind_to} must leave upgrades pending."
		);

		$table->maybe_upgrade();
	}

}
