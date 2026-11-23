<?php
/**
 * Database settings tab (Feature 134).
 *
 * Registers a "Database" tab on the shared AcrossAI Settings page
 * (`admin.php?page=acrossai-settings`) via the `acrossai_settings_tabs` filter and hosts a
 * React-driven UI for the database access policy:
 *
 *   - Access mode      (unrestricted / read-only / off)
 *   - Table denylist   (plus the two tables that are denied permanently)
 *   - Row and time caps
 *   - Value redaction and mutation logging
 *
 * Deliberately the same shape as {@see File_Manager_Settings_Menu}: same filter, same placeholder
 * section hosting a React mount, same Save-button suppression, because the panels save themselves
 * over REST. Two tabs that do the same job should not be two different implementations.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Admin/Partials
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Admin\Partials;

defined( 'ABSPATH' ) || exit;

/**
 * Database_Settings_Menu — the "Database" tab.
 */
class Database_Settings_Menu {

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
	public const TAB_SLUG = 'database';

	/**
	 * DOM id the React root mounts on.
	 */
	public const ROOT_ID = 'acrossai-database-settings-root';

	/**
	 * Register the "Database" tab.
	 *
	 * Priority 35 puts it immediately after File Manager (30), which is where an operator looking
	 * for "the other place access is controlled" will look for it.
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
			'label'    => __( 'Database', 'acrossai-abilities-manager' ),
			'priority' => 35,
		);

		return $tabs;
	}

	/**
	 * Register the placeholder settings section that hosts the React mount.
	 *
	 * The vendor TabbedPageRenderer wraps every tab in a Settings API form and calls
	 * `do_settings_sections()` on the tab-scoped page slug, so one section has to exist against that
	 * slug for React to have a container.
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

		add_settings_section(
			'acrossai_database_root',
			'', // Empty heading — the React panels supply their own.
			array( $this, 'render_root' ),
			$renderer->tab_page_slug( self::TAB_SLUG )
		);
	}

	/**
	 * Section-body render callback. Emits the React mount and a no-JS fallback.
	 *
	 * @return void
	 */
	public function render_root(): void {
		printf(
			'<div id="%1$s"></div><noscript><p><strong>%2$s</strong></p></noscript>',
			esc_attr( self::ROOT_ID ),
			esc_html__( 'The Database settings require JavaScript to be enabled.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Enqueue the React bundle when this tab is the active one.
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

		$asset_file = $build_path . 'js/abilities/database-settings.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			// Bundle not built yet — nothing to enqueue.
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'acrossai-database-settings',
			$build_url . 'js/abilities/database-settings.js',
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? '0.0.0' ),
			true
		);

		wp_localize_script(
			'acrossai-database-settings',
			'acrossaiDatabaseSettings',
			array(
				'restNamespace' => 'acrossai/v1',
				'restBase'      => 'database-settings',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			)
		);

		if ( file_exists( $build_path . 'css/abilities/database-settings.css' ) ) {
			wp_enqueue_style(
				'acrossai-database-settings',
				$build_url . 'css/abilities/database-settings.css',
				array(),
				(string) ( $asset['version'] ?? '0.0.0' )
			);
		}

		// The vendor form's Save button is unused here — each panel saves itself over REST, and
		// leaving a second Save on screen invites the operator to press the one that does nothing.
		wp_add_inline_style(
			'acrossai-database-settings',
			'.wrap form:has(#' . esc_attr( self::ROOT_ID ) . ') .submit { display: none !important; }'
		);
	}

	/**
	 * Whether the current admin page is the shared settings page with this tab active.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return bool
	 */
	private function is_active_tab_page( string $hook_suffix ): bool {
		if ( false === strpos( $hook_suffix, 'acrossai-settings' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab to render, not acting on it.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return self::TAB_SLUG === $tab;
	}
}
