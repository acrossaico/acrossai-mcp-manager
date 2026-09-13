<?php
/**
 * F037 T015-R — EmbedsTab REST controller tests (post-Pivot C).
 *
 * Rescoped from the original T015 which tested a bespoke `EmbedsController`
 * class (deleted per Pivot C). Post-Pivot, REST GET/POST is owned by
 * `AbstractReactMountServerTab::rest_read()` / `rest_save()` which delegate
 * to `EmbedsTab::get_state_for_server()` / `set_state_for_server()`.
 *
 * Covers:
 *   - FR-018 (revised) — permission gate (`manage_options`)
 *   - FR-018 (revised) — server-scoping via URL path parameter
 *   - FR-024 (revised) — 5-arg `acrossai_mcp_embed_transport_toggled` action
 *   - FR-009 (revised) — 3-arg `is_enabled_for_server()` signature
 *   - Pivot B — round-trip persistence via `_embeds_enabled` + `_embeds_clients` meta rows
 *   - SC-010 — observability firing matrix
 *   - R3 — fail-forward per-listener try/catch
 *
 * Runs under the `embeds` suite with WP bootstrap.
 *
 * @package AcrossAI_MCP_Manager\Tests\Embeds
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Embeds;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\EmbedsTab;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as ServerMetaQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

final class EmbedsTabRestTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private $server_id = 0;

	/**
	 * @var int
	 */
	private $admin_user_id = 0;

	/**
	 * @var int
	 */
	private $subscriber_user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		AbstractEmbedTransport::flush_cache();

		// Register REST routes (Registry hasn't fired admin_init in the test bootstrap).
		// WP 6.9 emits _doing_it_wrong for register_rest_route() called outside
		// rest_api_init. Registering directly is deliberate here — re-firing the
		// action would re-run every other listener — so declare the notice rather
		// than let the harness report it as unexpected.
		$this->setExpectedIncorrectUsage( 'register_rest_route' );
		EmbedsTab::instance()->register_rest_routes();

		// Create test users.
		$this->admin_user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Create a test server row directly via the MCPServer Query.
		$this->server_id = self::factory()->post->create(
			array( 'post_title' => 'Test MCP Server' )
		);
	}

	protected function tearDown(): void {
		ServerMetaQuery::delete_by_server_id( $this->server_id );
		AbstractEmbedTransport::flush_cache();
		parent::tearDown();
	}

	// ── Permission gate ─────────────────────────────────────────────────

	public function test_rest_get_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_user_id );

		$request  = new WP_REST_Request( WP_REST_Server::READABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status(), 'Subscriber MUST be denied per manage_options gate.' );
	}

	public function test_rest_post_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_user_id );

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => true,
				'items'  => array(),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status(), 'Subscriber POST MUST be denied.' );
	}

	public function test_rest_get_allowed_for_admin(): void {
		wp_set_current_user( $this->admin_user_id );

		$request  = new WP_REST_Request( WP_REST_Server::READABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'master', $data );
		$this->assertArrayHasKey( 'groups', $data );
	}

	// ── Round-trip persistence ──────────────────────────────────────────

	public function test_post_persists_master_toggle_in_meta_table(): void {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => true,
				'items'  => array(),
			)
		);
		rest_do_request( $request );

		$this->assertSame( '1', ServerMetaQuery::get_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER ) );
	}

	public function test_post_deletes_master_meta_row_when_off(): void {
		wp_set_current_user( $this->admin_user_id );

		// Prime: master ON.
		ServerMetaQuery::update_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER, '1' );

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => false,
				'items'  => array(),
			)
		);
		rest_do_request( $request );

		$this->assertNull( ServerMetaQuery::get_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER ), 'Master OFF MUST delete the meta row entirely (presence model).' );
	}

	public function test_post_persists_per_dto_state_in_json_blob(): void {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => true,
				'items'  => array(
					'npm'    => array( 'npx-acrossai-mcp-manager' => true ),
					'client' => array( 'claude-desktop' => true, 'vscode' => true ),
				),
			)
		);
		rest_do_request( $request );

		$items = AbstractEmbedTransport::get_items_for_server( $this->server_id );
		$this->assertSame( 1, (int) ( $items['npm'] ?? 0 ), 'NPM (single-item) stored as int 1.' );
		$this->assertContains( 'claude-desktop', $items['mcp-client'] ?? array(), 'Claude Desktop slug present in mcp-client array.' );
		$this->assertContains( 'vscode', $items['mcp-client'] ?? array(), 'VS Code slug present in mcp-client array.' );
	}

	public function test_get_response_shape(): void {
		wp_set_current_user( $this->admin_user_id );

		// Prime state.
		ServerMetaQuery::update_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER, '1' );
		AbstractEmbedTransport::save_items_for_server(
			$this->server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);

		$request  = new WP_REST_Request( WP_REST_Server::READABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['master'], 'Master state reflected in response.' );
		$this->assertIsArray( $data['groups'] );
		$this->assertGreaterThan( 0, count( $data['groups'] ), 'At least the 3 built-in transports MUST appear.' );

		// Verify Claude Desktop DTO is marked enabled inside the client group.
		$found = false;
		foreach ( $data['groups'] as $group ) {
			if ( 'client' === $group['key'] ) {
				foreach ( $group['dtos'] as $dto ) {
					if ( 'claude-desktop' === $dto['slug'] && true === $dto['enabled'] ) {
						$found = true;
						break 2;
					}
				}
			}
		}
		$this->assertTrue( $found, 'Claude Desktop enabled state MUST round-trip in the response.' );
	}

	// ── Observability actions (SC-010) ──────────────────────────────────

	public function test_master_toggle_fires_action_once_on_transition(): void {
		wp_set_current_user( $this->admin_user_id );
		$fires = 0;
		$captured_args = null;
		add_action(
			'acrossai_mcp_embed_master_toggled',
			static function ( $server_id, $enabled, $user_id ) use ( &$fires, &$captured_args ): void {
				++$fires;
				$captured_args = array( $server_id, $enabled, $user_id );
			},
			10,
			3
		);

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => true,
				'items'  => array(),
			)
		);
		rest_do_request( $request );

		$this->assertSame( 1, $fires, 'Master action MUST fire exactly once on a 0 → 1 transition.' );
		$this->assertSame( $this->server_id, $captured_args[0] );
		$this->assertTrue( $captured_args[1] );
		$this->assertSame( $this->admin_user_id, $captured_args[2] );
	}

	public function test_transport_toggle_fires_5_arg_action_per_dto(): void {
		wp_set_current_user( $this->admin_user_id );
		$captured = array();
		add_action(
			'acrossai_mcp_embed_transport_toggled',
			static function ( $server_id, $transport_key, $dto_slug, $enabled, $user_id ) use ( &$captured ): void {
				$captured[] = compact( 'server_id', 'transport_key', 'dto_slug', 'enabled', 'user_id' );
			},
			10,
			5
		);

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => true,
				'items'  => array(
					'client' => array( 'claude-desktop' => true, 'vscode' => true ),
				),
			)
		);
		rest_do_request( $request );

		$this->assertGreaterThanOrEqual( 2, count( $captured ), 'Action MUST fire at least once per DTO transition.' );

		// Verify the 5-arg signature is intact (Pivot A — $dto_slug is the 3rd arg).
		foreach ( $captured as $call ) {
			$this->assertIsString( $call['transport_key'] );
			$this->assertIsString( $call['dto_slug'], 'Post-Pivot-A: $dto_slug MUST be the 3rd argument.' );
			$this->assertIsBool( $call['enabled'] );
			$this->assertSame( $this->admin_user_id, $call['user_id'] );
		}
	}

	public function test_no_op_save_emits_no_actions(): void {
		wp_set_current_user( $this->admin_user_id );

		// Prime state: master ON + Claude Desktop enabled.
		ServerMetaQuery::update_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER, '1' );
		AbstractEmbedTransport::save_items_for_server(
			$this->server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);

		$master_fires = 0;
		$dto_fires    = 0;
		add_action( 'acrossai_mcp_embed_master_toggled', static function () use ( &$master_fires ) { ++$master_fires; } );
		add_action( 'acrossai_mcp_embed_transport_toggled', static function () use ( &$dto_fires ) { ++$dto_fires; } );

		// Submit the SAME state — should be a total no-op.
		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => true,
				'items'  => array(
					'client' => array( 'claude-desktop' => true ),
				),
			)
		);
		rest_do_request( $request );

		$this->assertSame( 0, $master_fires, 'No-op save MUST NOT fire master action.' );
		$this->assertSame( 0, $dto_fires, 'No-op save MUST NOT fire transport action.' );
	}

	public function test_listener_exception_does_not_roll_back_write(): void {
		wp_set_current_user( $this->admin_user_id );

		add_action(
			'acrossai_mcp_embed_master_toggled',
			static function (): void {
				throw new \RuntimeException( 'Simulated audit listener failure.' );
			}
		);

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => true,
				'items'  => array(),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'REST call MUST succeed despite listener throwing.' );
		$this->assertSame(
			'1',
			ServerMetaQuery::get_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER ),
			'DB write MUST have committed before the failing listener fired (R3 fail-forward).'
		);
	}

	public function test_throwing_listener_does_not_block_later_listeners(): void {
		wp_set_current_user( $this->admin_user_id );

		$fires_after_throw = 0;

		add_action(
			'acrossai_mcp_embed_master_toggled',
			static function (): void {
				throw new \RuntimeException( 'Listener #1 breaks.' );
			},
			10
		);
		add_action(
			'acrossai_mcp_embed_master_toggled',
			static function () use ( &$fires_after_throw ): void {
				++$fires_after_throw;
			},
			20 // Later priority — MUST still fire despite priority-10 throwing (R3 per-listener isolation).
		);

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/embeds' );
		$request->set_body_params(
			array(
				'master' => true,
				'items'  => array(),
			)
		);
		rest_do_request( $request );

		$this->assertSame(
			1,
			$fires_after_throw,
			'R3 per-listener isolation: listener at priority 20 MUST fire despite priority-10 listener throwing.'
		);
	}

	// ── Missing / invalid server ────────────────────────────────────────

	public function test_get_missing_server_returns_404(): void {
		wp_set_current_user( $this->admin_user_id );

		$request  = new WP_REST_Request( WP_REST_Server::READABLE, '/acrossai-mcp-manager/v1/servers/9999999/embeds' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}
}
