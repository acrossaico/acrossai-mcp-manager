<?php
/**
 * Feature 088 — predicates that decide which server rows the admin UI and
 * action handlers refuse to update or delete.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ProtectedServers;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ProtectedServersTest extends WP_UnitTestCase {

	/**
	 * Seed the managed rows for this test rather than inheriting them.
	 *
	 * Other classes in this suite TRUNCATE acrossai_mcp_servers. Their teardowns
	 * call DefaultServerSeeder::seed() to restore it, but that does not survive:
	 * WP_UnitTestCase runs with autocommit=0, so the INSERT after TRUNCATE's
	 * implicit COMMIT opens a fresh transaction that parent::tearDown() rolls
	 * back. Establishing the precondition here is what actually makes it true.
	 */
	public function set_up(): void {
		parent::set_up();
		DefaultServerSeeder::seed();
	}

	public function test_the_seeded_slug_is_protected(): void {
		$this->assertTrue( ProtectedServers::is_protected( DefaultServerSeeder::SLUG ) );
	}

	/**
	 * Protection follows OWNERSHIP, not history.
	 *
	 * The AcrossAI row is no longer seeded or reconciled, so the plugin has no
	 * business guarding it. Left protected, a site that already has one could
	 * never delete it — a lock with nothing behind it.
	 */
	public function test_the_withdrawn_acrossai_slug_is_not_protected(): void {
		$this->assertFalse( ProtectedServers::is_protected( DefaultServerSeeder::ACROSSAI_SLUG ) );
	}

	public function test_operator_created_and_empty_slugs_are_not_protected(): void {
		$this->assertFalse( ProtectedServers::is_protected( 'my-own-server' ) );
		$this->assertFalse( ProtectedServers::is_protected( '' ) );
	}

	/**
	 * Two key shapes are in circulation: Row::to_array() emits `server_slug`
	 * (server-edit screen + tab Registry) while MCPServerListTable remaps to
	 * `slug`. Both must resolve.
	 */
	public function test_is_protected_server_accepts_both_key_shapes(): void {
		$this->assertTrue(
			ProtectedServers::is_protected_server( array( 'server_slug' => DefaultServerSeeder::SLUG ) )
		);
		$this->assertTrue(
			ProtectedServers::is_protected_server( array( 'slug' => DefaultServerSeeder::SLUG ) )
		);
		$this->assertFalse(
			ProtectedServers::is_protected_server( array( 'slug' => 'my-own-server' ) )
		);
		$this->assertFalse( ProtectedServers::is_protected_server( array() ) );
	}

	public function test_is_protected_id_resolves_seeded_and_operator_rows(): void {
		$query = MCPServerQuery::instance();

		$seeded = $query->query(
			array(
				'server_slug' => DefaultServerSeeder::SLUG,
				'number'      => 1,
			)
		);
		$this->assertNotEmpty( $seeded, 'Activation must seed the default row.' );
		$this->assertTrue( ProtectedServers::is_protected_id( (int) $seeded[0]->id ) );

		$own_id = (int) $query->add_item(
			array(
				'server_name'            => 'Operator Server',
				'server_slug'            => 'operator-server',
				'description'            => '',
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'operator-server',
				'server_version'         => 'v1.0.0',
			)
		);
		$this->assertGreaterThan( 0, $own_id );
		$this->assertFalse( ProtectedServers::is_protected_id( $own_id ) );
	}

	public function test_is_protected_id_is_false_for_missing_and_invalid_ids(): void {
		$this->assertFalse( ProtectedServers::is_protected_id( 0 ) );
		$this->assertFalse( ProtectedServers::is_protected_id( -1 ) );
		$this->assertFalse( ProtectedServers::is_protected_id( 999999 ) );
	}

	/**
	 * Protection is NOT prominence.
	 *
	 * `is_recommended()` / `recommended_badge()` lived beside `is_protected()`
	 * and were removed with the AcrossAI promotion. Its premise — that BOTH
	 * seeded rows are equally protected — retired with the AcrossAI row itself;
	 * `test_the_withdrawn_acrossai_slug_is_not_protected()` above now holds the
	 * relevant half, that the surviving predicate did not inherit a promotion.
	 */
}
