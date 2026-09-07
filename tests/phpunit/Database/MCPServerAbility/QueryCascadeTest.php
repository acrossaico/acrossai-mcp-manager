<?php
/**
 * Feature 082 T043 + SEC-002 — server-delete cascade coverage for
 * `MCPServerAbility\Query::delete_items_for_server()` and its static
 * callback `Query::on_mcp_server_deleted()` wired on `mcp_server_deleted`.
 *
 * Mirrors the F020 `MCPServerTool\Query` cascade pattern (line-for-line).
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServerAbility
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\MCPServerAbility;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive test names.

class QueryCascadeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		MCPServerTable::instance()->maybe_upgrade();
		MCPServerAbilityTable::instance()->maybe_upgrade();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_server_abilities' ) );
	}

	private function count_rows_for_server( int $server_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE server_id = %d',
				$wpdb->prefix . 'acrossai_mcp_server_abilities',
				$server_id
			)
		);
	}

	private function seed_server( string $slug ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_servers',
			array(
				'server_name' => 'F082 cascade test — ' . $slug,
				'server_slug' => $slug,
				'is_enabled'  => 1,
			),
			array( '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * T043 — `delete_items_for_server()` clears every row for the target server.
	 */
	public function test_delete_items_for_server_clears_all_rows(): void {
		$server_id = $this->seed_server( 'f082-cascade-clear-' . uniqid() );

		MCPServerAbilityQuery::instance()->upsert( $server_id, 'core/a', true );
		MCPServerAbilityQuery::instance()->upsert( $server_id, 'core/b', false );
		MCPServerAbilityQuery::instance()->upsert( $server_id, 'core/c', true );

		$this->assertSame( 3, $this->count_rows_for_server( $server_id ) );

		$deleted = MCPServerAbilityQuery::instance()->delete_items_for_server( $server_id );

		$this->assertSame( 3, $deleted, 'delete_items_for_server MUST return the count of rows removed.' );
		$this->assertSame( 0, $this->count_rows_for_server( $server_id ), 'F082 FR-018 — every override row MUST be cleared.' );
	}

	/**
	 * T043 — `delete_items_for_server()` MUST NOT touch other servers' rows.
	 * Prevents cross-server data-loss if the wrong server_id is passed anywhere.
	 */
	public function test_delete_items_for_server_leaves_other_servers_intact(): void {
		$server_a = $this->seed_server( 'f082-cascade-a-' . uniqid() );
		$server_b = $this->seed_server( 'f082-cascade-b-' . uniqid() );

		MCPServerAbilityQuery::instance()->upsert( $server_a, 'core/x', true );
		MCPServerAbilityQuery::instance()->upsert( $server_b, 'core/y', true );
		MCPServerAbilityQuery::instance()->upsert( $server_b, 'core/z', false );

		MCPServerAbilityQuery::instance()->delete_items_for_server( $server_a );

		$this->assertSame( 0, $this->count_rows_for_server( $server_a ) );
		$this->assertSame( 2, $this->count_rows_for_server( $server_b ), 'Cross-server data loss check — server B rows MUST survive server A cascade.' );
	}

	/**
	 * SEC-002 — `on_mcp_server_deleted()` MUST NO-OP when the underlying
	 * server delete FAILED (`$result === false`). A failed server delete
	 * MUST NOT trigger cascade cleanup or the row-loss is real data damage.
	 *
	 * Mirrors F020's `MCPServerTool\Query::on_mcp_server_deleted` invariant.
	 */
	public function test_on_mcp_server_deleted_noops_when_result_is_false(): void {
		$server_id = $this->seed_server( 'f082-cascade-noop-fail-' . uniqid() );

		MCPServerAbilityQuery::instance()->upsert( $server_id, 'core/keep-me', true );
		$this->assertSame( 1, $this->count_rows_for_server( $server_id ) );

		// Simulate a failed server delete — cascade MUST NO-OP.
		MCPServerAbilityQuery::on_mcp_server_deleted( $server_id, false );

		$this->assertSame( 1, $this->count_rows_for_server( $server_id ), 'SEC-002 — on_mcp_server_deleted with $result=false MUST NOT clear rows.' );
	}

	/**
	 * SEC-002 — `on_mcp_server_deleted()` MUST NO-OP for invalid server_ids
	 * (0 or negative) even when `$result === true`. Prevents accidentally
	 * clearing all-rows-where-server_id=0.
	 */
	public function test_on_mcp_server_deleted_noops_when_server_id_invalid(): void {
		$server_id = $this->seed_server( 'f082-cascade-noop-invalid-' . uniqid() );

		MCPServerAbilityQuery::instance()->upsert( $server_id, 'core/keep-me-too', true );
		$this->assertSame( 1, $this->count_rows_for_server( $server_id ) );

		MCPServerAbilityQuery::on_mcp_server_deleted( 0, true );
		MCPServerAbilityQuery::on_mcp_server_deleted( -1, true );

		$this->assertSame( 1, $this->count_rows_for_server( $server_id ), 'SEC-002 — on_mcp_server_deleted with server_id <= 0 MUST NO-OP.' );
	}

	/**
	 * T043 END-TO-END — the wired `mcp_server_deleted` action MUST cascade to
	 * `on_mcp_server_deleted` and clear the ability rows. Exercises the full
	 * Main.php Loader wiring (T013) plus the callback itself.
	 *
	 * @return void
	 */
	public function test_mcp_server_deleted_action_fire_cascades_to_ability_rows(): void {
		$server_id = $this->seed_server( 'f082-cascade-e2e-' . uniqid() );

		MCPServerAbilityQuery::instance()->upsert( $server_id, 'core/e2e-a', true );
		MCPServerAbilityQuery::instance()->upsert( $server_id, 'core/e2e-b', false );
		$this->assertSame( 2, $this->count_rows_for_server( $server_id ) );

		// Fire the BerlinDB-level action directly. If Main.php wired the
		// listener, the rows disappear. If not, this test catches the
		// missing wiring — which would be a merge-blocker bug.
		do_action( 'mcp_server_deleted', $server_id, true );

		$this->assertSame( 0, $this->count_rows_for_server( $server_id ), 'F082 T013 wiring — mcp_server_deleted fire MUST cascade to on_mcp_server_deleted, clearing all rows.' );
	}

	/**
	 * T043 END-TO-END mirror — the wired action MUST NO-OP when result=false
	 * even if the server_id is valid. Confirms the wired listener honours
	 * the two-arg contract (`$server_id, $result`), not just the id.
	 */
	public function test_mcp_server_deleted_action_fire_noops_when_result_false(): void {
		$server_id = $this->seed_server( 'f082-cascade-e2e-fail-' . uniqid() );
		MCPServerAbilityQuery::instance()->upsert( $server_id, 'core/e2e-c', true );

		do_action( 'mcp_server_deleted', $server_id, false );

		$this->assertSame( 1, $this->count_rows_for_server( $server_id ), 'Wired listener MUST honour the two-arg contract — no cascade when $result=false.' );
	}
}
