<?php
/**
 * US6 — CliController::approve_auth_code() static method coverage.
 * Includes TASK-Q4 session-transient binding shape verification.
 *
 * @package AcrossAI_MCP_Manager\Tests\RestCli
 */

namespace AcrossAI_MCP_Manager\Tests\RestCli;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\REST\CliController;
use WP_UnitTestCase;

class ApproveAuthCodeStaticTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Tables already exist: tests/bootstrap-wp.php runs Activator::activate().
		// The Query::maybe_create_table() wrappers previously called here were
		// deleted by F011's BerlinDB migration.
	}

	private function seed_pending( string $code, string $server ): void {
		set_transient(
			CliController::AUTH_TRANSIENT_PREFIX . $code,
			array(
				'server_id'     => $server,
				'status'        => 'pending',
				'user_id'       => null,
				'session_token' => null,
				'created_at'    => time(),
			),
			CliController::AUTH_CODE_TTL
		);
	}

	public function test_pending_transient_flipped_to_approved(): void {
		$code = 'a1b2c3d4e5f60718293a4b5c6d7e8f10';
		$this->seed_pending( $code, 'srv-a' );

		$result = CliController::approve_auth_code( $code, 42 );
		$this->assertTrue( $result );

		$payload = get_transient( CliController::AUTH_TRANSIENT_PREFIX . $code );
		$this->assertSame( 'approved', $payload['status'] );
		$this->assertSame( 42, $payload['user_id'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $payload['session_token'] );
	}

	public function test_unknown_code_returns_false(): void {
		$this->assertFalse( CliController::approve_auth_code( 'never-issued', 1 ) );
	}

	public function test_already_approved_returns_false(): void {
		$code = 'a1b2c3d4e5f60718293a4b5c6d7e8f11';
		$this->seed_pending( $code, 'srv-b' );

		$this->assertTrue( CliController::approve_auth_code( $code, 1 ) );
		// Second call sees status='approved' → returns false.
		$this->assertFalse( CliController::approve_auth_code( $code, 1 ) );
	}

	public function test_session_transient_shape_binds_server_id(): void {
		// TASK-Q4 — session payload MUST be array{user_id, server_id}.
		$code = 'a1b2c3d4e5f60718293a4b5c6d7e8f12';
		$this->seed_pending( $code, 'srv-bound' );

		$this->assertTrue( CliController::approve_auth_code( $code, 99 ) );

		$e1            = get_transient( CliController::AUTH_TRANSIENT_PREFIX . $code );
		$session_token = (string) $e1['session_token'];

		$e2 = get_transient( CliController::SESSION_TRANSIENT_PREFIX . $session_token );
		$this->assertIsArray( $e2 );
		$this->assertArrayHasKey( 'user_id', $e2 );
		$this->assertArrayHasKey( 'server_id', $e2 );
		$this->assertSame( 99, (int) $e2['user_id'] );
		$this->assertSame( 'srv-bound', (string) $e2['server_id'] );
	}

	public function test_audit_row_written(): void {
		$code = 'a1b2c3d4e5f60718293a4b5c6d7e8f13';
		$this->seed_pending( $code, 'srv-audit' );

		CliController::approve_auth_code( $code, 7 );

		$rows = ( new CliAuthLogQuery() )->query(
			array( 'status' => 'approved', 'auth_code_hash' => hash( 'sha256', $code ) )
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 7, $rows[0]->user_id );
		$this->assertSame( 'srv-audit', $rows[0]->server_slug );
	}
}
