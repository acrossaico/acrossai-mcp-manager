<?php
/**
 * Feature 030 — save-handler unit coverage.
 *
 * Covers `Settings::handle_actions()` routing for the
 * `save_permission_override` action:
 *   T006 — bad nonce → wp_die (no DB write)
 *   T007 — missing manage_options capability → wp_die 403 (no DB write)
 *
 * Uses the `WPDieException` pattern (WP core test harness wraps `wp_die`)
 * plus a wp_redirect filter that throws to intercept the happy-path exit.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Tests\Admin\Partials;

use AcrossAI_MCP_Manager\Admin\Partials\Settings;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;
use WP_UnitTestCase;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class SettingsPermissionOverrideSaveTest extends WP_UnitTestCase {

	private int $server_id;
	private int $admin_user_id;
	private int $subscriber_user_id;

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		$this->reset_super_globals();

		$this->admin_user_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'                   => 'F030 Save Test Server',
				'server_slug'                   => 'f030-save-test',
				'is_enabled'                    => 1,
				'registered_from'               => 'database',
				'server_route_namespace'        => 'mcp',
				'server_route'                  => 'f030-save-test',
				'server_version'                => 'v1.0.0',
				'override_abilities_permission' => 0,
			)
		);
	}

	public function tearDown(): void {
		$this->reset_super_globals();
		$this->truncate_tables();
		// TRUNCATE implicitly COMMITs in MySQL, so it escapes WP_UnitTestCase's
		// per-test transaction rollback and permanently removes the default
		// server row that tests/bootstrap-wp.php seeds via Activator::activate().
		// Restore it, or every later test (and later suite — they share one DB)
		// sees a table with no seeded server.
		DefaultServerSeeder::seed();
		parent::tearDown();
	}

	public function test_bad_nonce_triggers_wp_die_and_does_not_write(): void {
		wp_set_current_user( $this->admin_user_id );

		$_GET['page']                                            = AdminPageSlugs::PARENT;
		$_GET['action']                                          = 'save_permission_override';
		$_GET['server']                                          = (string) $this->server_id;
		$_SERVER['REQUEST_METHOD']                               = 'POST';
		$_POST['override_abilities_permission']                  = '1';
		$_POST['acrossai_mcp_manager_permission_override_nonce'] = 'invalid-nonce-1234';
		$_REQUEST                                                = array_merge( $_GET, $_POST );

		add_filter( 'wp_die_handler', array( $this, 'throwing_wp_die_handler' ) );
		try {
			Settings::instance()->handle_actions();
			$this->fail( 'handle_actions() must wp_die on bad nonce.' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
		remove_filter( 'wp_die_handler', array( $this, 'throwing_wp_die_handler' ) );

		// DB assert — the flag MUST still be 0.
		$row = MCPServerQuery::instance()->query( array( 'id' => $this->server_id, 'number' => 1 ) )[0];
		$this->assertSame( 0, (int) $row->override_abilities_permission, 'Bad nonce MUST NOT permit a DB write.' );
	}

	public function test_missing_manage_options_capability_triggers_wp_die_403(): void {
		// Subscriber cannot manage_options — but subscriber CAN generate a
		// valid nonce for the action, so this specifically exercises the
		// capability gate inside handle_save_permission_override().
		wp_set_current_user( $this->subscriber_user_id );

		$nonce = wp_create_nonce( 'acrossai_mcp_manager_permission_override_' . $this->server_id );

		$_GET['page']                                            = AdminPageSlugs::PARENT;
		$_GET['action']                                          = 'save_permission_override';
		$_GET['server']                                          = (string) $this->server_id;
		$_SERVER['REQUEST_METHOD']                               = 'POST';
		$_POST['override_abilities_permission']                  = '1';
		$_POST['acrossai_mcp_manager_permission_override_nonce'] = $nonce;
		$_REQUEST                                                = array_merge( $_GET, $_POST );

		add_filter( 'wp_die_handler', array( $this, 'throwing_wp_die_handler' ) );
		try {
			Settings::instance()->handle_actions();
			$this->fail( 'handle_actions() must wp_die when user lacks manage_options.' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
		remove_filter( 'wp_die_handler', array( $this, 'throwing_wp_die_handler' ) );

		$row = MCPServerQuery::instance()->query( array( 'id' => $this->server_id, 'number' => 1 ) )[0];
		$this->assertSame( 0, (int) $row->override_abilities_permission, 'Missing cap MUST NOT permit a DB write.' );
	}

	/**
	 * Deliberately NOT named get_wp_die_handler(): that collides with
	 * WP_UnitTestCase_Base::get_wp_die_handler( $handler ), whose signature
	 * takes an argument, and PHP fatals on the incompatible declaration.
	 */
	public function throwing_wp_die_handler(): callable {
		return static function ( $message ): void {
			throw new \WPDieException( is_string( $message ) ? $message : 'wp_die called' );
		};
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
	}

	private function reset_super_globals(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}
}
