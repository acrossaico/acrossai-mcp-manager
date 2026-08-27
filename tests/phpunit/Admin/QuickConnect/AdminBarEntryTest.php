<?php
/**
 * F069 T046 — AdminBarEntry test.
 *
 * Locks two invariants:
 *   (a) node is registered for users with `manage_options` (admin/editor
 *       *with* cap, network super-admin).
 *   (b) node is NOT registered for users without the cap (subscriber,
 *       anonymous, editor without cap grant).
 *
 * Uses a real WP_Admin_Bar instance (not a mock) — cheaper to construct
 * than the fixture stack for admin-header rendering, and lets us assert
 * the exact node array shape.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\Admin\QuickConnect
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Admin\QuickConnect;

use AcrossAI_MCP_Manager\Admin\Partials\QuickConnect\AdminBarEntry;
use WP_Admin_Bar;
use WP_UnitTestCase;

final class AdminBarEntryTest extends WP_UnitTestCase {

	private const NODE_ID = 'acrossai-mcp-quick-connect';

	private int $admin_id      = 0;
	private int $subscriber_id = 0;

	public function setUp(): void {
		parent::setUp();

		// WP_Admin_Bar lives in wp-includes/class-wp-admin-bar.php — it's
		// autoloaded when the admin bar renders, but not by default in
		// PHPUnit. Require it explicitly if not yet loaded.
		if ( ! class_exists( 'WP_Admin_Bar' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		}

		$this->admin_id      = static::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = static::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_singleton_returns_same_instance(): void {
		$a = AdminBarEntry::instance();
		$b = AdminBarEntry::instance();
		$this->assertSame( $a, $b );
	}

	public function test_node_registered_for_admin(): void {
		wp_set_current_user( $this->admin_id );

		$bar = new WP_Admin_Bar();
		AdminBarEntry::instance()->register_node( $bar );

		$node = $bar->get_node( self::NODE_ID );
		$this->assertNotNull( $node, 'Admin bar node must be registered for administrators.' );
		$this->assertSame( self::NODE_ID, $node->id );
	}

	public function test_node_title_contains_wizard_label(): void {
		wp_set_current_user( $this->admin_id );

		$bar = new WP_Admin_Bar();
		AdminBarEntry::instance()->register_node( $bar );

		$node = $bar->get_node( self::NODE_ID );
		$this->assertStringContainsString( 'Quick Connect via AcrossAI', (string) $node->title );
		$this->assertStringContainsString( 'dashicons-admin-tools', (string) $node->title );
	}

	public function test_node_href_targets_wizard_step_one(): void {
		wp_set_current_user( $this->admin_id );

		$bar = new WP_Admin_Bar();
		AdminBarEntry::instance()->register_node( $bar );

		$node = $bar->get_node( self::NODE_ID );
		$this->assertStringContainsString( 'page=acrossai_mcp_manager', (string) $node->href );
		$this->assertStringContainsString( 'quick-connect=1', (string) $node->href );
		$this->assertStringContainsString( 'step=1', (string) $node->href );
	}

	public function test_node_absent_for_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );

		$bar = new WP_Admin_Bar();
		AdminBarEntry::instance()->register_node( $bar );

		$this->assertNull(
			$bar->get_node( self::NODE_ID ),
			'Admin bar node must NOT be registered for subscribers (no manage_options cap).'
		);
	}

	public function test_node_absent_for_logged_out_user(): void {
		wp_set_current_user( 0 );

		$bar = new WP_Admin_Bar();
		AdminBarEntry::instance()->register_node( $bar );

		$this->assertNull(
			$bar->get_node( self::NODE_ID ),
			'Admin bar node must NOT be registered when nobody is logged in.'
		);
	}
}
