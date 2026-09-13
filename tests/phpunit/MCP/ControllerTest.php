<?php
/**
 * MCP\Controller — smoke coverage for Phase 4 gap closure (Feature-009).
 *
 * Covers the 3 state-machine branches of Controller::get_adapter_status():
 *  - 'disabled' (no enabled rows)
 *  - 'not-found' (enabled rows exist but \WP\MCP\Plugin absent)
 *  - 'running' (enabled rows exist and adapter class is present)
 * Plus a singleton stability regression (B5 / S6 defense).
 *
 * The 'error' branch is not tested here — reproducing it requires stubbing
 * \WP\MCP\Plugin::instance() to throw, which is out of scope for a unit test.
 *
 * @package AcrossAI_MCP_Manager\Tests\MCP
 */

namespace AcrossAI_MCP_Manager\Tests\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\MCP\Controller;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- test methods self-document via descriptive names; matches existing tests/phpunit/* convention.

class ControllerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->reset_controller_singleton();
		$this->truncate_mcp_server_table();
	}

	public function tearDown(): void {
		$this->reset_controller_singleton();
		$this->truncate_mcp_server_table();
		parent::tearDown();
	}

	public function test_singleton_instance_is_stable(): void {
		$a = Controller::instance();
		$b = Controller::instance();
		$this->assertSame( $a, $b );
	}

	public function test_no_enabled_servers_returns_disabled(): void {
		// Empty MCP server table.
		$status = Controller::instance()->get_adapter_status();
		$this->assertSame( 'disabled', $status );
	}

	public function test_enabled_server_present_returns_not_found_when_adapter_absent(): void {
		// This test relies on \WP\MCP\Plugin NOT being autoloadable in the test
		// harness. If the adapter package IS loadable (e.g. CI installs it as
		// a dev dep), the branch under test becomes 'running' instead and the
		// separate test_running_branch case covers it.
		if ( class_exists( '\WP\MCP\Plugin' ) ) {
			$this->markTestSkipped( 'WP\\MCP\\Plugin is loadable in this environment; the not-found branch requires an environment WITHOUT the adapter package.' );
		}

		$this->seed_enabled_server( 'test-server-1' );

		$status = Controller::instance()->get_adapter_status();
		$this->assertSame( 'not-found', $status );
	}

	public function test_running_branch_when_adapter_loadable(): void {
		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			$this->markTestSkipped( 'WP\\MCP\\Plugin is not loadable in this environment; the running branch requires the adapter package installed.' );
		}

		$this->seed_enabled_server( 'test-server-2' );

		$status = Controller::instance()->get_adapter_status();
		$this->assertSame( 'running', $status );
	}

	public function test_get_adapter_status_is_idempotent(): void {
		// Empty table → first call sets 'disabled'; subsequent calls return the
		// cached value without re-running the DB query. Verify the state
		// doesn't flip on repeat calls with the same DB state.
		$controller = Controller::instance();

		$first = $controller->get_adapter_status();
		$this->seed_enabled_server( 'added-after-first-call' );
		$second = $controller->get_adapter_status();

		$this->assertSame( 'disabled', $first );
		$this->assertSame(
			'disabled',
			$second,
			'Once initialize_adapter() runs, the cached status is stable — new rows added afterwards do not flip the value mid-request.'
		);
	}

	private function reset_controller_singleton(): void {
		// The singleton's $_instance is protected; use reflection to reset it
		// between tests so each test observes a fresh state machine.
		$ref = new \ReflectionClass( Controller::class );
		$prop = $ref->getProperty( '_instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	private function seed_enabled_server( string $slug ): void {
		MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Test Server ' . $slug,
				'server_slug'            => $slug,
				'description'            => 'Seeded by ControllerTest',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
	}

	private function truncate_mcp_server_table(): void {
		global $wpdb;
		// The table is `acrossai_mcp_servers`. The old name here silently made
		// every TRUNCATE a no-op ('table doesn't exist'), so tests asserting a
		// server-free state ran against whatever rows the suite had left.
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}
}
