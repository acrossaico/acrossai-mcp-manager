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

	public function test_both_seeded_slugs_are_protected(): void {
		$this->assertTrue( ProtectedServers::is_protected( DefaultServerSeeder::SLUG ) );
		$this->assertTrue( ProtectedServers::is_protected( DefaultServerSeeder::ACROSSAI_SLUG ) );
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
			ProtectedServers::is_protected_server( array( 'server_slug' => DefaultServerSeeder::ACROSSAI_SLUG ) )
		);
		$this->assertTrue(
			ProtectedServers::is_protected_server( array( 'slug' => DefaultServerSeeder::ACROSSAI_SLUG ) )
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
				'server_slug' => DefaultServerSeeder::ACROSSAI_SLUG,
				'number'      => 1,
			)
		);
		$this->assertNotEmpty( $seeded, 'Activation must seed the AcrossAI row.' );
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
	 * Exactly one row is surfaced as "Recommended" — the AcrossAI one. This
	 * drives both the list-table pin and the badge.
	 */
	public function test_only_the_acrossai_row_is_recommended(): void {
		$this->assertTrue( ProtectedServers::is_recommended( DefaultServerSeeder::ACROSSAI_SLUG ) );
		$this->assertFalse( ProtectedServers::is_recommended( DefaultServerSeeder::SLUG ) );
		$this->assertFalse( ProtectedServers::is_recommended( 'my-own-server' ) );
	}

	public function test_recommended_badge_markup(): void {
		$this->assertStringContainsString(
			'acrossai-recommended-badge',
			ProtectedServers::recommended_badge()
		);
	}
}
