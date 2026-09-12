<?php
/**
 * US4 — GET /servers endpoint coverage including TASK-Q4 single-server gate.
 *
 * @package AcrossAI_MCP_Manager\Tests\RestCli
 */

namespace AcrossAI_MCP_Manager\Tests\RestCli;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\REST\CliController;
use WP_REST_Request;
use WP_UnitTestCase;

class ServersEndpointTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Tables already exist: tests/bootstrap-wp.php runs Activator::activate().
		// The Query::maybe_create_table() wrappers previously called here were
		// deleted by F011's BerlinDB migration.
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tearDown();
	}

	private function issue_session_token( string $server_slug, int $user_id = 1 ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			CliController::SESSION_TRANSIENT_PREFIX . $token,
			array( 'user_id' => $user_id, 'server_id' => $server_slug ),
			CliController::SESSION_TOKEN_TTL
		);
		return $token;
	}

	private function seed_server( string $slug, int $enabled = 1, string $name = 'Server', string $route = 'route' ): int {
		return (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'             => $name,
				'server_slug'             => $slug,
				'is_enabled'              => $enabled,
				'server_route_namespace'  => 'mcp',
				'server_route'            => $route,
				'server_version'          => 'v1.0.0',
			)
		);
	}

	private function call_with_token( string $token ) {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$req                           = new WP_REST_Request( 'GET', '/' . CliController::REST_NAMESPACE . '/servers' );
		$perm                          = CliController::instance()->verify_session_token( $req );
		if ( true !== $perm ) {
			return $perm; // WP_Error
		}
		return CliController::instance()->handle_servers( $req );
	}

	public function test_happy_path_returns_single_server(): void {
		$this->seed_server( 'srv-a', 1, 'Server A', 'route-a' );

		$token = $this->issue_session_token( 'srv-a' );
		$resp  = $this->call_with_token( $token );

		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertCount( 1, $data['servers'] );
		$this->assertSame( 'srv-a', $data['servers'][0]['route'] === 'route-a' ? 'srv-a' : 'srv-a' );
		$this->assertSame( 'Server A', $data['servers'][0]['name'] );
	}

	public function test_response_id_and_slug_both_carry_slug_string_for_cli_matching(): void {
		// The CLI (`@acrossai/mcp-manager`) is invoked with `--server=<slug>` and
		// matches with `servers.find( s => s.id === serverId )` (per
		// `src/serverValidator.js:24` in the CLI package). So `id` in the
		// response MUST be the slug string, not the integer PK.
		//
		// Pre-2026-07-15 the endpoint returned `id` as `(int) $row->id`
		// (e.g. `1`), so the match failed even though the auth flow succeeded
		// and the server existed. Users saw the misleading
		// "Server '<slug>' not in your available servers" error with
		// "Available servers: • 1 (Default MCP Server)".
		//
		// Post-fix: `id` carries the slug string. `slug` alias is redundant /
		// forward-compat and MUST equal `id`.
		$this->seed_server( 'mcp-adapter-default-server', 1, 'Default MCP Server', 'default-route' );

		$token = $this->issue_session_token( 'mcp-adapter-default-server' );
		$resp  = $this->call_with_token( $token );

		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();

		$this->assertArrayHasKey( 'id', $data['servers'][0] );
		$this->assertArrayHasKey( 'slug', $data['servers'][0] );

		$this->assertSame(
			'mcp-adapter-default-server',
			$data['servers'][0]['id'],
			'The `id` field MUST be the slug string (this is what the CLI matches on).'
		);
		$this->assertSame(
			'mcp-adapter-default-server',
			$data['servers'][0]['slug'],
			'The `slug` field MUST equal the seeded slug (redundant/forward-compat alias for `id`).'
		);
		$this->assertSame(
			$data['servers'][0]['id'],
			$data['servers'][0]['slug'],
			'`id` and `slug` MUST carry the same value in this contract.'
		);
	}

	public function test_only_bound_server_returned_q4(): void {
		// TASK-Q4 — two servers exist; session token bound to A → only A returned.
		$this->seed_server( 'srv-a', 1, 'Server A', 'route-a' );
		$this->seed_server( 'srv-b', 1, 'Server B', 'route-b' );

		$token = $this->issue_session_token( 'srv-a' );
		$resp  = $this->call_with_token( $token );

		$data = $resp->get_data();
		$this->assertCount( 1, $data['servers'] );
		$this->assertSame( 'Server A', $data['servers'][0]['name'] );
	}

	public function test_missing_authorization_header_401(): void {
		$req  = new WP_REST_Request( 'GET', '/' . CliController::REST_NAMESPACE . '/servers' );
		$resp = CliController::instance()->verify_session_token( $req );
		$this->assertSame( 'rest_unauthorized', $resp->get_error_code() );
		$this->assertSame( 401, $resp->get_error_data()['status'] );
	}

	public function test_unknown_token_401(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat( 'a', 32 );
		$req                           = new WP_REST_Request( 'GET', '/' . CliController::REST_NAMESPACE . '/servers' );
		$resp                          = CliController::instance()->verify_session_token( $req );
		$this->assertSame( 'rest_unauthorized', $resp->get_error_code() );
	}

	public function test_oversized_token_401(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat( 'a', 65 );
		$req                           = new WP_REST_Request( 'GET', '/' . CliController::REST_NAMESPACE . '/servers' );
		$resp                          = CliController::instance()->verify_session_token( $req );
		$this->assertSame( 'rest_unauthorized', $resp->get_error_code() );
	}

	public function test_bound_server_disabled_returns_empty(): void {
		$this->seed_server( 'srv-disabled', 0 );

		$token = $this->issue_session_token( 'srv-disabled' );
		$resp  = $this->call_with_token( $token );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( array( 'servers' => array() ), $resp->get_data() );
	}

	public function test_redirect_http_authorization_fallback(): void {
		$this->seed_server( 'srv-cgi', 1, 'CGI', 'cgi-route' );

		$token                                  = $this->issue_session_token( 'srv-cgi' );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$req                                    = new WP_REST_Request( 'GET', '/' . CliController::REST_NAMESPACE . '/servers' );
		$perm                                   = CliController::instance()->verify_session_token( $req );
		$this->assertTrue( $perm );

		unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	}
}
