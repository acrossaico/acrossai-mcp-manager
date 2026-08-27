<?php
/**
 * AdminBarEntry — persistent "Quick Connect via AcrossAI" chip in the admin bar.
 *
 * Feature 069 T044 (US2) — Provides a one-click entry point to the wizard
 * from anywhere in wp-admin. Rendered only for users with `manage_options`
 * (the wizard's baseline capability); everyone else sees nothing.
 *
 * The node label uses the `dashicons-admin-tools` wrench glyph so it matches
 * the existing plugin submenu iconography.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/QuickConnect
 * @since      0.2.11
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\QuickConnect;

use WP_Admin_Bar;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Quick Connect via AcrossAI" node on the wp-admin toolbar.
 *
 * @since 0.2.11
 */
final class AdminBarEntry {

	/**
	 * Node id — MUST be unique across the admin bar.
	 */
	private const NODE_ID = 'acrossai-mcp-quick-connect';

	/**
	 * Singleton instance.
	 *
	 * @since 0.2.11
	 * @var self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Singleton accessor.
	 *
	 * @since 0.2.11
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor enforces singleton pattern (S6).
	 *
	 * @since 0.2.11
	 */
	private function __construct() {}

	/**
	 * `admin_bar_menu` callback — register the wizard chip.
	 *
	 * Fires at priority 100 (well after WP core / most plugins), so the node
	 * lands at the right-hand side of the toolbar next to user meta.
	 *
	 * @since 0.2.11
	 * @param WP_Admin_Bar $wp_admin_bar The current admin bar instance.
	 * @return void
	 */
	public function register_node( WP_Admin_Bar $wp_admin_bar ): void {
		// Capability gate — hide the chip entirely for anyone who can't
		// use the wizard. Same gate as QuickConnectController::permission_check().
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => '<span class="ab-icon dashicons dashicons-admin-tools" style="top:3px;"></span>'
					. esc_html__( 'Quick Connect via AcrossAI', 'acrossai-mcp-manager' ),
				'href'  => esc_url( admin_url( 'admin.php?page=acrossai_mcp_manager&quick-connect=1&step=1' ) ),
				'meta'  => array(
					'title' => __( 'Guided 5-step MCP configuration', 'acrossai-mcp-manager' ),
				),
			)
		);
	}
}
