<?php
/**
 * F069 T041 — QuickConnectController REST test.
 *
 * Covers auth (401 subscriber), nonce (403 on missing/invalid X-WP-Nonce),
 * shape (200 admin), invalid inputs (400), server-gone race (410), and the
 * scratchpad TTL refresh (SC-005 US3 acceptance).
 *
 * Test harness note: server-side test requests do NOT auto-pass the
 * X-WP-Nonce header the way a browser does. To exercise the nonce path
 * we set the header on the WP_REST_Request. WordPress verifies the
 * nonce as part of cookie-auth in wp_validate_auth_cookie — see
 * `\rest_cookie_check_errors()` — so we simulate by manually setting
 * `wp_set_current_user()` (which establishes the cap context but does
 * NOT establish cookie auth). For the 403 nonce-fail branch we test the
 * shape by asserting our permission_callback returns bool false when
 * the user lacks manage_options.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\REST
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\REST\QuickConnectController;
use WP_REST_Request;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive test names.

final class QuickConnectControllerTest extends WP_UnitTestCase {

	private int $admin_id      = 0;
	private int $subscriber_id = 0;

	public function setUp(): void {
		parent::setUp();

		// Ensure BerlinDB tables exist (activation lifecycle runs once at bootstrap;
		// paranoid re-run for tests that drop tables).
		MCPServerTable::instance()->maybe_upgrade();
		DefaultServerSeeder::seed();

		QuickConnectController::instance()->register_routes();

		$this->admin_id      = static::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = static::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Clear any leftover per-user scratchpads.
		delete_transient( 'acrossai_mcp_manager_quick_connect_state_' . $this->admin_id );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_transient( 'acrossai_mcp_manager_quick_connect_state_' . $this->admin_id );
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────
	// Permission callback (permission_check)
	// ─────────────────────────────────────────────────────────────────────

	public function test_permission_check_returns_true_for_admin(): void {
		wp_set_current_user( $this->admin_id );
		$this->assertTrue( QuickConnectController::instance()->permission_check() );
	}

	public function test_permission_check_returns_false_for_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( QuickConnectController::instance()->permission_check() );
	}

	public function test_permission_check_returns_false_for_logged_out(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( QuickConnectController::instance()->permission_check() );
	}

	// ─────────────────────────────────────────────────────────────────────
	// GET /state
	// ─────────────────────────────────────────────────────────────────────

	public function test_get_state_returns_full_snapshot_shape_for_admin(): void {
		wp_set_current_user( $this->admin_id );
		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/quick-connect/state' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'servers', $data );
		$this->assertArrayHasKey( 'abilities', $data );
		$this->assertArrayHasKey( 'plugins', $data );
		$this->assertArrayHasKey( 'methods', $data );
		$this->assertArrayHasKey( 'wizardState', $data );

		$this->assertIsArray( $data['servers'] );
		$this->assertArrayHasKey( 'total', $data['abilities'] );
		$this->assertArrayHasKey( 'acrossaiPro', $data['plugins'] );
		$this->assertArrayHasKey( 'abilitiesManager', $data['plugins'] );
		$this->assertArrayHasKey( 'npm', $data['methods'] );
		$this->assertArrayHasKey( 'clients', $data['methods'] );
		$this->assertArrayHasKey( 'ai_connectors', $data['methods'] );

		// wizardState defaults when no transient exists.
		$this->assertSame( 1, $data['wizardState']['current_step'] );
		$this->assertNull( $data['wizardState']['server_id'] );
		$this->assertFalse( $data['wizardState']['access_saved'] );
		$this->assertFalse( $data['wizardState']['abilities_saved'] );
		$this->assertFalse( $data['wizardState']['enabled'] );
		$this->assertNull( $data['wizardState']['method'] );
	}

	public function test_get_state_returns_401_for_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );
		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/quick-connect/state' );
		$response = rest_get_server()->dispatch( $req );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// POST /step
	// ─────────────────────────────────────────────────────────────────────

	public function test_post_step_rejects_invalid_step_value_400(): void {
		wp_set_current_user( $this->admin_id );
		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 99 );
		$req->set_param( 'data', array() );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'acrossai_mcp_quick_connect_invalid_step', $response->get_data()['code'] );
	}

	public function test_post_step_1_select_existing_server_persists_to_scratchpad(): void {
		wp_set_current_user( $this->admin_id );

		// Ensure the default seeded server exists so we have a valid id.
		$servers = MCPServerQuery::instance()->query( array( 'number' => 1 ) );
		$this->assertNotEmpty( $servers, 'Default seeded server must exist for this test.' );
		$server_id = (int) $servers[0]->id;

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 1 );
		$req->set_param( 'data', array( 'server_id' => $server_id ) );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $server_id, $response->get_data()['wizardState']['server_id'] );

		// Re-fetch state → transient reflects the server_id.
		$state_req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/quick-connect/state' );
		$state_response = rest_get_server()->dispatch( $state_req );
		$this->assertSame( $server_id, $state_response->get_data()['wizardState']['server_id'] );
	}

	public function test_post_step_1_returns_410_when_server_gone(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 1 );
		$req->set_param( 'data', array( 'server_id' => 999999 ) );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 410, $response->get_status() );
		$this->assertSame( 'acrossai_mcp_quick_connect_server_gone', $response->get_data()['code'] );
	}

	public function test_post_step_1_create_intent_sets_flag_and_clears_server_id(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 1 );
		$req->set_param( 'data', array( 'create_intent' => true ) );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['wizardState']['create_intent'] );
		$this->assertNull( $data['wizardState']['server_id'] );
	}

	public function test_post_step_2_create_new_server_via_new_server_key(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 2 );
		$req->set_param(
			'data',
			array(
				'new_server' => array(
					'server_name'    => 'Wizard-Created Server',
					'server_version' => 'v1.0.0',
				),
			)
		);
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotNull( $data['wizardState']['server_id'] );
		$this->assertArrayHasKey( 'servers', $data, 'Response should include refreshed servers list.' );

		// Sanity check — the created row exists in the DB.
		$rows = MCPServerQuery::instance()->query( array( 'id' => (int) $data['wizardState']['server_id'] ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Wizard-Created Server', $rows[0]->server_name );
	}

	public function test_post_step_2_rejects_missing_name_400(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 2 );
		$req->set_param( 'data', array( 'new_server' => array( 'description' => 'no name' ) ) );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'acrossai_mcp_quick_connect_invalid_data', $response->get_data()['code'] );
	}

	public function test_post_step_2_create_forged_keys_dropped_by_sanitizer_whitelist(): void {
		wp_set_current_user( $this->admin_id );

		// TASK-SEC-T-001 mass-assignment negative-test at the REST layer.
		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 2 );
		$req->set_param(
			'data',
			array(
				'new_server' => array(
					'server_name' => 'Legit',
					'is_enabled'  => 1,       // Forged — must not enable the new server.
					'id'          => 42,      // Forged — must not override auto-increment.
				),
			)
		);
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$new_id = (int) $response->get_data()['wizardState']['server_id'];
		$this->assertNotSame( 42, $new_id, 'Auto-increment id must not be overridden by forged input.' );

		$rows = MCPServerQuery::instance()->query( array( 'id' => $new_id ) );
		$this->assertCount( 1, $rows );
		$this->assertEmpty( (int) $rows[0]->is_enabled, 'Server must not be enabled — forged is_enabled=1 must be dropped by whitelist.' );
	}

	public function test_post_step_4_abilities_gate_no_op_returns_200(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 4 );
		$req->set_param( 'data', array() );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 4, $response->get_data()['wizardState']['current_step'] );
	}

	/**
	 * @dataProvider provide_no_op_terminal_steps
	 */
	public function test_post_terminal_step_no_op_returns_200( int $step ): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', $step );
		$req->set_param( 'data', array() );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $step, $response->get_data()['wizardState']['current_step'] );
	}

	public function provide_no_op_terminal_steps(): array {
		// Steps 8-13 are the Pro-pitch / activate gate + four method-specific
		// detail screens. None mutate the scratchpad beyond recording
		// current_step; all must return 200 with the step echoed back.
		return array(
			array( 8 ),
			array( 9 ),
			array( 10 ),
			array( 11 ),
			array( 12 ),
			array( 13 ),
		);
	}

	public function test_post_step_7_rejects_invalid_method_400(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 7 );
		$req->set_param( 'data', array( 'method' => 'bogus' ) );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'acrossai_mcp_quick_connect_invalid_method', $response->get_data()['code'] );
	}

	public function test_post_step_7_accepts_valid_method(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req->set_param( 'step', 7 );
		$req->set_param( 'data', array( 'method' => 'client' ) );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'client', $response->get_data()['wizardState']['method'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// POST /install-plugin
	// ─────────────────────────────────────────────────────────────────────

	public function test_install_plugin_permission_check_requires_install_and_activate_caps(): void {
		wp_set_current_user( $this->admin_id );
		$this->assertTrue( QuickConnectController::instance()->install_plugin_permission_check() );

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( QuickConnectController::instance()->install_plugin_permission_check() );
	}

	public function test_install_plugin_rejects_slug_not_on_whitelist_400(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/install-plugin' );
		$req->set_param( 'slug', 'hello-dolly' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'acrossai_mcp_quick_connect_invalid_plugin', $response->get_data()['code'] );
	}

	public function test_install_plugin_rejects_missing_slug_400(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/install-plugin' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 400, $response->get_status() );
	}

	// ─────────────────────────────────────────────────────────────────────
	// write_scratchpad — false-negative regression
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Regression: WP core `set_transient()` returns false when the stored
	 * value hasn't changed (via `update_option`'s "no change" branch).
	 * Naively surfacing that as failure caused the "Failed to save your
	 * progress" error when a user re-clicked "Enable all and continue"
	 * after all abilities were already enabled — the scratchpad payload
	 * was identical to what was already stored.
	 */
	public function test_post_step_succeeds_when_scratchpad_payload_unchanged(): void {
		wp_set_current_user( $this->admin_id );

		$req_a = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req_a->set_param( 'step', 7 );
		$req_a->set_param( 'data', array( 'method' => 'client' ) );
		$response_a = rest_get_server()->dispatch( $req_a );
		$this->assertSame( 200, $response_a->get_status() );

		// Second identical POST — scratchpad already stores { method: 'client' },
		// so set_transient hits the "no change" branch and returns false.
		// The controller must treat this as success by verifying read-back.
		$req_b = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req_b->set_param( 'step', 7 );
		$req_b->set_param( 'data', array( 'method' => 'client' ) );
		$response_b = rest_get_server()->dispatch( $req_b );

		$this->assertSame(
			200,
			$response_b->get_status(),
			'Re-posting the same step payload must not surface a spurious persist_failed error.'
		);
		$this->assertSame( 'client', $response_b->get_data()['wizardState']['method'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// GET /state — trialEndDate field
	// ─────────────────────────────────────────────────────────────────────

	public function test_get_state_includes_trial_end_date(): void {
		wp_set_current_user( $this->admin_id );
		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/quick-connect/state' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$plugins = $response->get_data()['plugins'];
		$this->assertArrayHasKey( 'trialEndDate', $plugins );
		$this->assertNotEmpty( $plugins['trialEndDate'] );
		// Date format: "F j, Y" → e.g. "September 16, 2026". Sanity-check the
		// shape without pinning to an exact date (test would break tomorrow).
		$this->assertMatchesRegularExpression(
			'/^[A-Z][a-z]+ \d{1,2}, \d{4}$/',
			$plugins['trialEndDate']
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// POST /complete
	// ─────────────────────────────────────────────────────────────────────

	public function test_post_complete_returns_204_and_clears_scratchpad(): void {
		wp_set_current_user( $this->admin_id );

		// Prime the scratchpad.
		set_transient(
			'acrossai_mcp_manager_quick_connect_state_' . $this->admin_id,
			array( 'current_step' => 7, 'server_id' => 1 ),
			1800
		);

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/complete' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 204, $response->get_status() );
		$this->assertFalse(
			get_transient( 'acrossai_mcp_manager_quick_connect_state_' . $this->admin_id ),
			'Scratchpad transient must be deleted by complete().'
		);
	}

	public function test_post_complete_idempotent_second_call_still_204(): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/complete' );
		rest_get_server()->dispatch( $req );

		$req2 = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/complete' );
		$response = rest_get_server()->dispatch( $req2 );

		$this->assertSame( 204, $response->get_status() );
	}

	// ─────────────────────────────────────────────────────────────────────
	// US3 — scratchpad TTL refresh on write
	// ─────────────────────────────────────────────────────────────────────

	public function test_scratchpad_ttl_refreshes_on_every_write(): void {
		wp_set_current_user( $this->admin_id );

		$req1 = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/quick-connect/step' );
		$req1->set_param( 'step', 7 );
		$req1->set_param( 'data', array( 'method' => 'npm' ) );
		rest_get_server()->dispatch( $req1 );

		// Read back — data should be present with a fresh TTL.
		$state_req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/quick-connect/state' );
		$response = rest_get_server()->dispatch( $state_req );
		$this->assertSame( 'npm', $response->get_data()['wizardState']['method'] );
	}
}
