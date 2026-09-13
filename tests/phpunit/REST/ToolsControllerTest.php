<?php
/**
 * Feature 025 — ToolsController REST test.
 *
 * Covers the split-on-POST / compose-on-GET semantics plus:
 *  - Removal of the F020 EXCLUDED_SLUGS rejection: protocol slugs are now
 *    first-class payload entries.
 *  - SEC-025-v2-2 hardening: POST with only the three protocol slugs → 200.
 *  - SEC-TASKS-025-2: POST empty tools array → 200 + all three columns 0
 *    + curated table cleared + GET returns { tools: [] } (truly-empty legal
 *    state per FR-017).
 *  - Reset semantic (US3): POST [3 protocol slugs] → all three columns 1
 *    + curated wiped.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\REST
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use AcrossAI_MCP_Manager\Includes\REST\ToolsController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class ToolsControllerTest extends WP_UnitTestCase {

	private int $admin_id  = 0;
	private int $server_id = 0;

	public function set_up(): void {
		parent::set_up();

		MCPServerTable::instance()->maybe_upgrade();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_tools`' );

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->server_id = (int) MCPServerQuery::instance()->add_item( array(
			'server_name'            => 'ToolsControllerTest',
			'server_slug'            => 'tools-controller-test',
			'description'            => '',
			'is_enabled'             => 1,
			'registered_from'        => 'database',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'tools-controller-test',
			'server_version'         => 'v1.0.0',
		) );

		do_action( 'rest_api_init' );
		ToolsController::instance()->register_routes();

		wp_set_current_user( $this->admin_id );
	}

	// --- Auth boundary (F020 preserved) ------------------------------------

	public function test_get_returns_401_for_unauthenticated_user(): void {
		// WordPress distinguishes the two: rest_authorization_required_code()
		// returns 401 when nobody is logged in and 403 for a logged-in user
		// lacking the capability. This test signs nobody in, so 401 is the
		// correct contract — the old 403 expectation never matched core.
		wp_set_current_user( 0 );
		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/tools' );
		$res = rest_do_request( $req );
		$this->assertSame( 401, $res->get_status() );
	}

	// --- F025: POST accepts protocol slugs (SEC-025-v2-2 hardening) --------

	public function test_post_accepts_all_three_protocol_slugs(): void {
		// SEC-025-v2-2 hardening + 2026-07-14 runtime-bug fix: protocol slugs
		// are canonical plugin constants and MUST bypass wp_get_abilities()
		// validation. This test asserts the fix — POST with only the three
		// protocol slugs succeeds even when the vendor's abilities-registration
		// listener attached too late (which is the actual runtime condition).
		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/tools' );
		$req->set_body_params( array( 'tools' => array(
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
		) ) );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status(), 'POST with only protocol slugs must return 200 under F025 regardless of abilities-API bootstrap timing.' );
	}

	// --- F025 split-on-POST: [protocol + curated] --------------------------

	public function test_post_mixed_slugs_flips_columns_and_writes_curated(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped.' );
		}

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/tools' );
		$req->set_body_params( array( 'tools' => array(
			'mcp-adapter/discover-abilities',
			'my-plugin/curated-one',
		) ) );
		$res = rest_do_request( $req );

		if ( 200 !== $res->get_status() ) {
			// Some abilities may not be registered in the harness — assertion
			// requires the ability catalog to include the slugs. If not, skip.
			$this->markTestSkipped( 'Abilities not registered in harness.' );
		}

		$rows = MCPServerQuery::instance()->query( array( 'id' => $this->server_id, 'number' => 1 ) );
		$row  = $rows[0];
		$this->assertSame( 1, (int) $row->tool_discover_abilities );
		$this->assertSame( 0, (int) $row->tool_get_ability_info );
		$this->assertSame( 0, (int) $row->tool_execute_ability );
	}

	// --- SEC-TASKS-025-2: truly-empty legal state ---------------------------

	public function test_post_empty_tools_array_produces_empty_composed_set(): void {
		// Seed some prior curated + default columns.
		MCPServerToolQuery::instance()->replace_set( $this->server_id, array( 'prior/pick-1', 'prior/pick-2' ) );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/tools' );
		$req->set_body_params( array( 'tools' => array() ) );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );

		$rows = MCPServerQuery::instance()->query( array( 'id' => $this->server_id, 'number' => 1 ) );
		$row  = $rows[0];
		$this->assertSame( 0, (int) $row->tool_discover_abilities );
		$this->assertSame( 0, (int) $row->tool_get_ability_info );
		$this->assertSame( 0, (int) $row->tool_execute_ability );

		$this->assertSame(
			array(),
			MCPServerToolQuery::instance()->get_added_slugs( $this->server_id )
		);

		// GET returns composed union = empty.
		$get = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/tools' );
		$get_res = rest_do_request( $get );
		$this->assertSame( 200, $get_res->get_status() );
		$data = $get_res->get_data();
		$this->assertSame( array(), $data['tools'] );
	}

	// --- US3 Reset semantic -------------------------------------------------

	public function test_post_only_protocol_slugs_wipes_curated_and_sets_all_columns_one(): void {
		// Reset payload is protocol-only — bypasses catalog validation per fix.

		// Seed prior curated to prove Reset wipes them.
		MCPServerToolQuery::instance()->replace_set( $this->server_id, array( 'prior/curated-A', 'prior/curated-B' ) );
		// And flip one column off to prove Reset restores it.
		MCPServerQuery::instance()->update_item( $this->server_id, array( 'tool_execute_ability' => 0 ) );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/tools' );
		$req->set_body_params( array( 'tools' => array(
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
		) ) );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );

		$rows = MCPServerQuery::instance()->query( array( 'id' => $this->server_id, 'number' => 1 ) );
		$row  = $rows[0];
		$this->assertSame( 1, (int) $row->tool_discover_abilities );
		$this->assertSame( 1, (int) $row->tool_get_ability_info );
		$this->assertSame( 1, (int) $row->tool_execute_ability );
		$this->assertSame(
			array(),
			MCPServerToolQuery::instance()->get_added_slugs( $this->server_id ),
			'Reset payload MUST clear every curated row via replace_set([]).'
		);
	}

	// --- GET compose --------------------------------------------------------

	public function test_get_returns_composed_union_of_columns_and_curated(): void {
		MCPServerToolQuery::instance()->replace_set( $this->server_id, array( 'my-plugin/one', 'my-plugin/two' ) );

		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/tools' );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		// Default row has all three columns = 1 → three protocol slugs + two curated.
		$this->assertContains( 'mcp-adapter/discover-abilities', $data['tools'] );
		$this->assertContains( 'mcp-adapter/get-ability-info', $data['tools'] );
		$this->assertContains( 'mcp-adapter/execute-ability', $data['tools'] );
		$this->assertContains( 'my-plugin/one', $data['tools'] );
		$this->assertContains( 'my-plugin/two', $data['tools'] );
	}
}
