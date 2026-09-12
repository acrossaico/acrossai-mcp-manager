<?php
/**
 * CliAuthLog\Recorder — verify both static methods persist correct rows
 * + audit failures are silent (best-effort).
 *
 * @package AcrossAI_MCP_Manager\Tests\RestCli
 */

namespace AcrossAI_MCP_Manager\Tests\RestCli;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP_UnitTestCase;

class RecorderTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Tables already exist: tests/bootstrap-wp.php runs Activator::activate().
		// The Query::maybe_create_table() wrappers previously called here were
		// deleted by F011's BerlinDB migration.
	}

	public function test_record_approved_persists_row_with_expected_columns(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name' => 'Test',
				'server_slug' => 'test-srv',
				'is_enabled'  => 1,
			)
		);

		$hash = hash( 'sha256', 'auth-code-abc' );
		Recorder::record_approved( 42, 'test-srv', $hash );

		$rows = CliAuthLogQuery::instance()->query( array( 'status' => 'approved', 'auth_code_hash' => $hash ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $server_id, $rows[0]->server_id );
		$this->assertSame( 'test-srv', $rows[0]->server_slug );
		$this->assertSame( 42, $rows[0]->user_id );
		$this->assertSame( 'approved', $rows[0]->status );
		$this->assertSame( $hash, $rows[0]->auth_code_hash );
		$this->assertNotNull( $rows[0]->approved_at );
	}

	public function test_record_success_persists_row_with_app_password_uuid(): void {
		MCPServerQuery::instance()->add_item(
			array(
				'server_name' => 'Srv',
				'server_slug' => 'srv-success',
				'is_enabled'  => 1,
			)
		);

		$hash = hash( 'sha256', 'auth-code-success' );
		$uuid = '01234567-89ab-cdef-0123-456789abcdef';
		Recorder::record_success( 7, 'srv-success', $hash, $uuid );

		$rows = CliAuthLogQuery::instance()->query( array( 'status' => 'success', 'auth_code_hash' => $hash ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 7, $rows[0]->user_id );
		$this->assertSame( 'srv-success', $rows[0]->server_slug );
		$this->assertSame( 'success', $rows[0]->status );
		$this->assertSame( $hash, $rows[0]->auth_code_hash );
		$this->assertSame( $uuid, $rows[0]->app_password_uuid );
		$this->assertNotNull( $rows[0]->completed_at );
	}

	public function test_unknown_server_slug_writes_row_with_zero_server_id(): void {
		// Don't pre-seed an MCPServer row — slug doesn't resolve.
		$hash = hash( 'sha256', 'graceful-degrade' );
		Recorder::record_approved( 99, 'nonexistent-server', $hash );

		$rows = CliAuthLogQuery::instance()->query( array( 'status' => 'approved', 'auth_code_hash' => $hash ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]->server_id );
		$this->assertSame( 'nonexistent-server', $rows[0]->server_slug );
	}
}
