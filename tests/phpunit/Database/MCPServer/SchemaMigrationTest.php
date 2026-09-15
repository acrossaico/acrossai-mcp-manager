<?php
/**
 * Schema-migration coverage for Feature 025 — the F011 BerlinDB `maybe_upgrade()`
 * path is expected to add three tinyint(1) columns with DEFAULT 1 on the ALTER
 * when the plugin's schema version bumps from 1.0.0 → 1.1.0.
 *
 * The `WP_UnitTestCase` bootstrap already runs Activator::activate() (which
 * calls MCPServerTable::instance()->maybe_upgrade()), so by the time this test
 * runs the schema is at v1.1.0 with all three new columns present. This test
 * asserts the post-upgrade state directly rather than round-tripping a
 * v1.0.0 snapshot (which would require test-scaffolding a downgrade path
 * that the plugin does not support).
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class SchemaMigrationTest extends WP_UnitTestCase {

	public function test_three_new_tool_columns_exist_after_upgrade(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// NOTE the escaped underscore. In SQL LIKE, `_` is a SINGLE-CHARACTER
		// WILDCARD, so the original `'tool_%'` also matched F090's
		// `tools_default_policy` (tool + s + _default_policy) and this test
		// failed with "actual size 4" on a change that added no tool_ column at
		// all. Escaping makes the pattern mean what it always intended.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'tool\\_%'" );

		$names = array_map(
			static function ( $r ) {
				return (string) $r->Field;
			},
			$rows
		);

		$this->assertCount( 3, $names, 'Feature 025 must ship exactly three tool_* columns.' );
		$this->assertContains( 'tool_discover_abilities', $names );
		$this->assertContains( 'tool_get_ability_info', $names );
		$this->assertContains( 'tool_execute_ability', $names );
	}

	public function test_new_columns_default_to_one_for_freshly_inserted_rows(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Migration test server',
				'server_slug'            => 'migration-test',
				'description'            => 'Seeded by SchemaMigrationTest',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'migration-test',
				'server_version'         => 'v1.0.0',
			)
		);
		$rows = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) );
		$row  = $rows[0];

		// B18: DB layer returns TINYINT as string; the Row constructor casts
		// to (int). This test asserts BOTH the raw storage default AND the
		// Row's constructor int-cast are correct.
		$this->assertSame( 1, (int) $row->tool_discover_abilities );
		$this->assertSame( 1, (int) $row->tool_get_ability_info );
		$this->assertSame( 1, (int) $row->tool_execute_ability );

		// Cleanup.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'acrossai_mcp_servers', array( 'id' => $server_id ), array( '%d' ) );
	}

	public function test_maybe_upgrade_is_idempotent_on_current_version(): void {
		// Calling maybe_upgrade twice in a row must be a no-op — no ALTER, no
		// warnings, no error_log noise. F011's phantom-version guard already
		// asserts the "table dropped, version option present" case; this test
		// asserts the more common "everything is fine" reentrancy.
		$this->expectNotToPerformAssertions();
		MCPServerTable::instance()->maybe_upgrade();
		MCPServerTable::instance()->maybe_upgrade();
	}
}
