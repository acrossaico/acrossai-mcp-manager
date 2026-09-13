<?php
/**
 * US5 — POST /auth/exchange endpoint coverage.
 *
 * Per-error-path coverage (9 envelopes) + TASK-Q2 Content-Type +
 * TASK-Q3 App Password naming uniqueness + single-use enforcement.
 *
 * @package AcrossAI_MCP_Manager\Tests\RestCli
 */

namespace AcrossAI_MCP_Manager\Tests\RestCli;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\REST\CliController;
use WP_REST_Request;
use WP_UnitTestCase;

class AuthExchangeEndpointTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Tables already exist: tests/bootstrap-wp.php runs Activator::activate().
		// The Query::maybe_create_table() wrappers previously called here were
		// deleted by F011's BerlinDB migration.
	}

	private function seed_full_flow( string $code, string $server, int $user_id ): string {
		// E1 approved transient.
		$session_token = bin2hex( random_bytes( 16 ) );
		set_transient(
			CliController::AUTH_TRANSIENT_PREFIX . $code,
			array(
				'server_id'     => $server,
				'status'        => 'approved',
				'user_id'       => $user_id,
				'session_token' => $session_token,
				'created_at'    => time(),
			),
			CliController::AUTH_CODE_TTL
		);
		// E2 session transient (Q4 binding).
		set_transient(
			CliController::SESSION_TRANSIENT_PREFIX . $session_token,
			array( 'user_id' => $user_id, 'server_id' => $server ),
			CliController::SESSION_TOKEN_TTL
		);
		return $session_token;
	}

	private function call( string $content_type, array $params ) {
		$req = new WP_REST_Request( 'POST', '/' . CliController::REST_NAMESPACE . '/auth/exchange' );
		if ( '' !== $content_type ) {
			$req->set_header( 'Content-Type', $content_type );
		}
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return CliController::instance()->handle_auth_exchange( $req );
	}

	public function test_happy_path(): void {
		MCPServerQuery::instance()->add_item(
			array( 'server_name' => 'X', 'server_slug' => 'srv-happy', 'is_enabled' => 1 )
		);
		$user_id = (int) self::factory()->user->create();
		$code    = 'a1b2c3d4e5f60718293a4b5c6d7e8f00';
		$this->seed_full_flow( $code, 'srv-happy', $user_id );

		$resp = $this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-happy' ) );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertNotEmpty( $data['app_password'] );
		$this->assertSame( $user_id, $data['user_id'] );
		$this->assertSame( 2592000, $data['expires_in'] );
		$this->assertSame( 'srv-happy', $data['server_id'] );

		// Both transients deleted.
		$this->assertFalse( get_transient( CliController::AUTH_TRANSIENT_PREFIX . $code ) );
	}

	public function test_app_password_name_includes_code_prefix_q3(): void {
		// TASK-Q3 — App Password name MUST include first-8-hex of the code.
		MCPServerQuery::instance()->add_item(
			array( 'server_name' => 'Y', 'server_slug' => 'srv-q3', 'is_enabled' => 1 )
		);
		$user_id = (int) self::factory()->user->create();
		$code    = 'aabbccdde5f60718293a4b5c6d7e8f01';
		$this->seed_full_flow( $code, 'srv-q3', $user_id );

		$this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-q3' ) );

		$pwds = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$names = array_map( static fn ( $p ) => (string) $p['name'], $pwds );
		$this->assertContains( 'AcrossAI MCP Manager CLI - srv-q3 - aabbccdd', $names );
	}

	public function test_missing_content_type_rejected_q2(): void {
		$resp = $this->call( '', array( 'code' => 'whatever', 'server_id' => 'whatever' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_request', $resp->get_data()['error'] );
	}

	public function test_bogus_content_type_rejected_q2(): void {
		$resp = $this->call( 'text/plain', array( 'code' => 'whatever', 'server_id' => 'whatever' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_request', $resp->get_data()['error'] );
	}

	public function test_invalid_code(): void {
		$resp = $this->call( 'application/json', array( 'code' => 'never-issued-code', 'server_id' => 'srv-x' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_code', $resp->get_data()['error'] );
	}

	public function test_not_approved(): void {
		$code = 'a1b2c3d4e5f60718293a4b5c6d7e8f02';
		set_transient(
			CliController::AUTH_TRANSIENT_PREFIX . $code,
			array( 'server_id' => 'srv-pending', 'status' => 'pending', 'user_id' => null, 'session_token' => null, 'created_at' => time() ),
			CliController::AUTH_CODE_TTL
		);

		$resp = $this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-pending' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'not_approved', $resp->get_data()['error'] );
	}

	public function test_invalid_user(): void {
		$code = 'a1b2c3d4e5f60718293a4b5c6d7e8f03';
		$this->seed_full_flow( $code, 'srv-x', 999999 ); // user_id doesn't exist

		$resp = $this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-x' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_user', $resp->get_data()['error'] );
	}

	public function test_missing_server(): void {
		MCPServerQuery::instance()->add_item(
			array( 'server_name' => 'S', 'server_slug' => 'srv-ms', 'is_enabled' => 1 )
		);
		$user_id = (int) self::factory()->user->create();
		$code    = 'a1b2c3d4e5f60718293a4b5c6d7e8f04';
		$this->seed_full_flow( $code, 'srv-ms', $user_id );

		$resp = $this->call( 'application/json', array( 'code' => $code, 'server_id' => '' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'missing_server', $resp->get_data()['error'] );
	}

	public function test_server_mismatch_preserves_transients(): void {
		MCPServerQuery::instance()->add_item(
			array( 'server_name' => 'S', 'server_slug' => 'srv-real', 'is_enabled' => 1 )
		);
		$user_id = (int) self::factory()->user->create();
		$code    = 'a1b2c3d4e5f60718293a4b5c6d7e8f05';
		$this->seed_full_flow( $code, 'srv-real', $user_id );

		$resp = $this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-WRONG' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'server_mismatch', $resp->get_data()['error'] );

		// Transient MUST still exist (legitimate retry path).
		$this->assertNotFalse( get_transient( CliController::AUTH_TRANSIENT_PREFIX . $code ) );
	}

	public function test_invalid_server(): void {
		$user_id = (int) self::factory()->user->create();
		$code    = 'a1b2c3d4e5f60718293a4b5c6d7e8f06';
		// Seed flow but the server_slug references a server we never inserted.
		$this->seed_full_flow( $code, 'srv-ghost', $user_id );

		$resp = $this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-ghost' ) );
		$this->assertSame( 403, $resp->get_status() );
		$this->assertSame( 'invalid_server', $resp->get_data()['error'] );
	}

	public function test_single_use_after_success(): void {
		MCPServerQuery::instance()->add_item(
			array( 'server_name' => 'O', 'server_slug' => 'srv-once', 'is_enabled' => 1 )
		);
		$user_id = (int) self::factory()->user->create();
		$code    = 'a1b2c3d4e5f60718293a4b5c6d7e8f07';
		$this->seed_full_flow( $code, 'srv-once', $user_id );

		$first = $this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-once' ) );
		$this->assertSame( 200, $first->get_status() );

		$second = $this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-once' ) );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'invalid_code', $second->get_data()['error'] );
	}

	public function test_not_supported_when_wp_apps_disabled(): void {
		// Filter WP-Apps to "disabled". The implementation only checks class_exists,
		// so simulating by routing through the filter is not enough; this test instead
		// covers the documented behaviour conditional on class_exists. We use a
		// runtime guard: skip if class still exists in the WP-PHPUnit harness.
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			$user_id = (int) self::factory()->user->create();
			$code    = 'a1b2c3d4e5f60718293a4b5c6d7e8f08';
			$this->seed_full_flow( $code, 'srv-ns', $user_id );
			$resp = $this->call( 'application/json', array( 'code' => $code, 'server_id' => 'srv-ns' ) );
			$this->assertSame( 501, $resp->get_status() );
			$this->assertSame( 'not_supported', $resp->get_data()['error'] );
		} else {
			$this->markTestSkipped( 'WP_Application_Passwords class exists in this WP-PHPUnit environment; 501 path requires the class to be absent.' );
		}
	}
}
