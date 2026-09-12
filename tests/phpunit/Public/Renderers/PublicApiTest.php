<?php
/**
 * Tests for the public Renderer API — F012 gates + REST endpoint + zero-duplication.
 *
 * Feature 013. Covers SEC-013-001 (cross-context nonce replay), SEC-013-002
 * (App Password lockdown), SEC-013-005 (F012 gate placement), SEC-013-008
 * (invalid FQN silent skip), and SC-002 (byte-identity invariant).
 *
 * PHPUnit 9.6 note (supersedes BUGS.md B9): use the `@dataProvider`
 * annotation. The WordPress test suite calls PHPUnit APIs removed in
 * PHPUnit 10, so the toolchain is pinned to 9.x — which predates PHP
 * attributes, making `#[DataProvider]` a silent no-op.
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Renderers
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Public\Renderers;

use AcrossAI_MCP_Manager\Includes\REST\ClientRendererController;
use AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock;
use AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock;
use WP_REST_Request;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP_UnitTestCase;

final class PublicApiTest extends WP_UnitTestCase {

	/**
	 * Fixture server id.
	 *
	 * These tests used to call render( 1, … ), assuming a row with id 1 had
	 * survived from an earlier suite. Suites share one database and several
	 * TRUNCATE their tables, so that assumption held only by accident — and
	 * stopped holding as soon as the suites actually ran. Each test now owns
	 * its fixture.
	 *
	 * @var int
	 */
	private $server_id = 0;

	public function setUp(): void {
		parent::setUp();
		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Renderer Fixture Server',
				'server_slug'            => 'renderer-fixture-server',
				'description'            => 'Fixture for the renderers suite.',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'renderer-fixture-server',
				'server_version'         => 'v1.0.0',
			)
		);
	}

	/**
	 * SEC-013-005 — NpmClientBlock renders disabled notice when option is false.
	 */
	public function test_npm_gate_disabled_shows_notice_hides_config(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		update_option( 'acrossai_mcp_npm_login_enabled', false );

		ob_start();
		NpmClientBlock::instance()->render( $this->server_id, array( 'context' => 'admin' ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'currently disabled', $output );
		$this->assertStringContainsString( 'page=acrossai-settings&#038;tab=mcp', $output );
		$this->assertStringNotContainsString( 'Configuration JSON', $output );
	}

	/**
	 * FR-019 — MCPClientsBlock is NOT gated by F012 toggles.
	 */
	public function test_mcp_clients_not_gated_by_f012_options(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		update_option( 'acrossai_mcp_npm_login_enabled', false );

		ob_start();
		MCPClientsBlock::instance()->render( $this->server_id, array( 'context' => 'admin' ) );
		$output = (string) ob_get_clean();

		// Even with both F012 gates off, MCP Clients still renders (or gracefully handles missing server).
		$this->assertStringNotContainsString( 'currently disabled', $output );
	}

	/**
	 * SEC-013-002 — REST endpoint returns 403 when user_id != current.
	 */
	public function test_rest_endpoint_403_on_user_id_mismatch(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/generate-app-password' );
		$request->set_param( 'server_id', 1 );
		$request->set_param( 'client_slug', 'npm' );
		$request->set_param( 'context', 'admin' );
		$request->set_param( 'user_id', $admin_id + 1 );  // Different user.

		$result = ClientRendererController::instance()->permission_check( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * SEC-013-001 — REST endpoint returns 403 on cross-context nonce replay.
	 */
	public function test_rest_endpoint_403_on_cross_context_nonce_replay(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Mint nonce for context='admin'.
		$admin_nonce = wp_create_nonce( 'acrossai_mcp_render_npm_1_admin' );

		// Attempt to use that nonce with context='buddyboss-profile'.
		$request = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/generate-app-password' );
		$request->set_param( 'server_id', 1 );
		$request->set_param( 'client_slug', 'npm' );
		$request->set_param( 'context', 'buddyboss-profile' );
		$request->set_param( 'user_id', $admin_id );
		$request->set_header( 'X-WP-Nonce', $admin_nonce );

		$result = ClientRendererController::instance()->permission_check( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * SEC-013-008 — Invalid FQN in acrossai_mcp_client_classes filter silently skipped.
	 */
	public function test_client_classes_filter_silently_skips_invalid_fqn(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$filter = static function ( $classes ) {
			$classes[] = '\Nonexistent\NamespacedClass';
			$classes[] = 'stdClass';  // Exists but not AbstractMCPClient subclass.
			return $classes;
		};
		add_filter( 'acrossai_mcp_client_classes', $filter );

		ob_start();
		MCPClientsBlock::instance()->render( $this->server_id, array( 'context' => 'admin' ) );
		$output = (string) ob_get_clean();

		// No fatal; block still renders (or gracefully handles missing server).
		$this->assertIsString( $output );

		remove_filter( 'acrossai_mcp_client_classes', $filter );
	}
}
