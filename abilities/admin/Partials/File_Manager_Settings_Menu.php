<?php
/**
 * File Manager settings tab (Feature 092).
 *
 * Registers a new "File Manager" tab on the shared AcrossAI Settings page
 * (`admin.php?page=acrossai-settings`) via the `acrossai_settings_tabs`
 * filter and hosts a React-driven UI for the three settings:
 *
 *   - Write allowlist  (Path_Allowlist_Guard::OPTION_WRITE)
 *   - Read allowlist   (Path_Allowlist_Guard::OPTION_READ)
 *   - Redaction config (Secret_Redactor::OPTION)
 *
 * The `acrossai-co/main-menu` TabbedPageRenderer wraps each tab in a
 * WordPress Settings API form. We register a placeholder settings section
 * whose render callback emits the React mount div plus a note explaining
 * that saves happen automatically via REST from within each React panel
 * (the form's Save button is unused for this tab).
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Admin/Partials
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Admin\Partials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * File_Manager_Settings_Menu — the "File Manager" tab.
 */
class File_Manager_Settings_Menu {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Tab slug — becomes the `?tab=` query-arg on the settings page.
	 */
	public const TAB_SLUG = 'file-manager';

	/**
	 * DOM id the React root mounts on.
	 */
	public const ROOT_ID = 'acrossai-file-manager-settings-root';

	/**
	 * Register the "File Manager" tab on the shared AcrossAI Settings page.
	 *
	 * Hooked to the `acrossai_settings_tabs` filter provided by
	 * `acrossai-co/main-menu` v0.0.14+.
	 *
	 * @param mixed $tabs Tabs collected so far.
	 * @return array
	 */
	public function register_tab( $tabs ): array {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}
		$tabs[] = array(
			'slug'     => self::TAB_SLUG,
			'label'    => __( 'File Manager', 'acrossai-abilities-manager' ),
			'priority' => 30,
		);
		return $tabs;
	}

	/**
	 * Register the placeholder settings section that hosts the React mount.
	 *
	 * The TabbedPageRenderer wraps every tab in a Settings API form and calls
	 * `do_settings_sections()` on the tab-scoped page slug — so we need one
	 * section registered against that slug to give React a container. The
	 * section render callback emits the mount div; React takes over from there.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		if ( ! class_exists( '\\AcrossAI_Main_Menu\\SettingsPage' ) ) {
			return;
		}
		$renderer = \AcrossAI_Main_Menu\SettingsPage::get_settings_renderer();
		if ( ! $renderer ) {
			return;
		}
		$page_slug = $renderer->tab_page_slug( self::TAB_SLUG );

		add_settings_section(
			'acrossai_file_manager_root',
			'', // Empty section heading — the React panels supply their own.
			array( $this, 'render_root' ),
			$page_slug
		);
	}

	/**
	 * Section-body render callback. Emits the React mount + a small note.
	 *
	 * @return void
	 */
	public function render_root(): void {
		printf(
			'<div id="%1$s"></div><noscript><p><strong>%2$s</strong></p></noscript>',
			esc_attr( self::ROOT_ID ),
			esc_html__( 'The File Manager settings require JavaScript to be enabled.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Enqueue the React bundle when this tab is the active one on the shared
	 * settings page.
	 *
	 * Hooked to `admin_enqueue_scripts`.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_active_tab_page( $hook_suffix ) ) {
			return;
		}

		$build_url  = defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL' )
			? \ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL . 'build/'
			: plugins_url( 'build/', dirname( __DIR__, 3 ) . '/acrossai-mcp-manager.php' );
		$build_path = defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH' )
			? \ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH . 'build/'
			: dirname( __DIR__, 3 ) . '/build/';

		$asset_file = $build_path . 'js/abilities/file-manager-settings.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			// Bundle hasn't been built yet — nothing to enqueue.
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'acrossai-file-manager-settings',
			$build_url . 'js/abilities/file-manager-settings.js',
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? '0.0.0' ),
			true
		);

		wp_localize_script(
			'acrossai-file-manager-settings',
			'acrossaiFileManagerSettings',
			array(
				'restNamespace' => 'acrossai/v1',
				'restBase'      => 'file-manager-settings',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			)
		);

		if ( file_exists( $build_path . 'css/abilities/file-manager-settings.css' ) ) {
			wp_enqueue_style(
				'acrossai-file-manager-settings',
				$build_url . 'css/abilities/file-manager-settings.css',
				array(),
				(string) ( $asset['version'] ?? '0.0.0' )
			);
		}

		// Suppress the vendor tab's Save button — our React panels save
		// themselves via REST and the form Save is unused / confusing here.
		wp_add_inline_style(
			'acrossai-file-manager-settings',
			'.wrap form:has(#' . esc_attr( self::ROOT_ID ) . ') .submit { display: none !important; }'
		);
	}

	/**
	 * Detect whether the current admin page is the shared settings page AND
	 * this tab is the active one.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return bool
	 */
	private function is_active_tab_page( string $hook_suffix ): bool {
		// The shared settings page hook is stable across setups — the page
		// slug is 'acrossai-settings' and lands as 'acrossai_page_acrossai-settings'.
		if ( false === strpos( $hook_suffix, 'acrossai-settings' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return self::TAB_SLUG === $tab;
	}
}
