<?php
/**
 * Admin-bar entry point for the Quick Connect wizard.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/Admin/Partials/QuickConnect
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Admin\Partials\QuickConnect;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds a Quick Connect chip to the admin toolbar.
 *
 * The wizard opens by itself exactly once, and only on a site with no
 * recommended transport. Everyone else — anyone who dismissed it, anyone who
 * already had a transport when they installed, anyone who wants to re-read the
 * walkthroughs — needs a way back in. The toolbar is the one surface present on
 * every admin screen, so it is the reliable route.
 *
 * @since 0.0.34
 */
class AdminBarEntry {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.34
	 * @var   self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- plugin-wide singleton convention.

	/**
	 * Toolbar node id.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const NODE_ID = 'acrossai-abilities-quick-connect';

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.0.34
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Private constructor — use instance().
	 *
	 * @since 0.0.34
	 */
	private function __construct() {}

	/**
	 * Register the toolbar node.
	 *
	 * Hooked to `admin_bar_menu` at priority 100 — late enough that the node
	 * settles to the right of core's own entries.
	 *
	 * @since  0.0.34
	 * @param  \WP_Admin_Bar $wp_admin_bar Current toolbar instance.
	 * @return void
	 */
	public function register_node( $wp_admin_bar ): void {
		// Hidden entirely for anyone who cannot use the wizard, rather than
		// shown and then refused — same gate as the page itself.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! is_object( $wp_admin_bar ) || ! method_exists( $wp_admin_bar, 'add_node' ) ) {
			return;
		}

		// Suppressed while the wizard is already on screen: a link to the page
		// you are looking at is noise, and the wizard hides the rest of the
		// admin chrome anyway.
		if ( QuickConnectPage::instance()->is_quick_connect_request() ) {
			return;
		}

		// Suppressed entirely on sites running AcrossAI MCP Manager, which puts
		// its own Quick Connect chip on this same strip.
		if ( ! EntryPoints::is_available() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => self::NODE_ID,
				// Full name outside the AcrossAI menu, where "Quick Connect" alone
				// would not say whose. The sidebar entry keeps the short form.
				'title' => '<span class="ab-icon dashicons dashicons-admin-tools" style="top:3px;"></span>'
					. esc_html__( 'Quick Connect via AcrossAI', 'acrossai-abilities-manager' ),
				'href'  => esc_url( self::wizard_url() ),
				'meta'  => array(
					'title' => __( 'Set up AcrossAI Abilities Manager', 'acrossai-abilities-manager' ),
				),
			)
		);
	}

	/**
	 * Canonical entry URL for the wizard.
	 *
	 * Thin delegate to EntryPoints, which is the shared home for the URL and the
	 * visibility rule alike.
	 *
	 * @since  0.0.34
	 * @return string Admin URL for step 1.
	 */
	public static function wizard_url(): string {
		return EntryPoints::wizard_url();
	}
}
