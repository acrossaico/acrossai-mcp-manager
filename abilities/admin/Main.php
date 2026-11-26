<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/admin
 * @since      0.0.1
 */

namespace AcrossAI_Abilities_Manager\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://github.com/acrosswp/acrossai-abilities-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/admin
 * @author     AcrossWP <deepak@acrosswp.com>
 */
class Main {

	/**
	 * The ID of this plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * The js_asset_file of the backend
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $js_asset_file;

	/**
	 * The css_asset_file of the backend
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $css_asset_file;


	/**
	 * Asset manifest for the Custom Abilities JS/CSS bundle.
	 *
	 * @since    0.2.0
	 * @access   private
	 * @var      array|null
	 */
	private $abilities_asset_file;

	/**
	 * Asset manifest for the MCP Manager Abilities-tab extension JS bundle.
	 *
	 * Feature 044: enqueued only on the sibling `acrossai-mcp-manager` plugin's
	 * per-server Abilities tab. Appends an Action column via the sibling's
	 * `acrossaiMcpManager.abilities.fields` JS filter.
	 *
	 * @since    0.0.6
	 * @access   private
	 * @var      array|null
	 */
	private $mcp_extension_asset_file;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    0.0.1
	 * @param      string $plugin_name       The name of this plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		$this->js_asset_file  = include \ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH . 'build/js/abilities/backend.asset.php';
		$this->css_asset_file = include \ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH . 'build/css/abilities/backend.asset.php';

		// Load Custom Abilities asset file if it exists (built by @wordpress/scripts build).
		$abilities_asset_path = \ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH . 'build/js/abilities/abilities.asset.php';
		if ( file_exists( $abilities_asset_path ) ) {
			$this->abilities_asset_file = include $abilities_asset_path;
		} elseif ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// Log a notice when the build artifact is absent so developers can diagnose missing builds.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'acrossai-abilities-manager: build/js/abilities/abilities.asset.php not found — run npm run build.' );
		}

		// Feature 044: MCP Manager Abilities-tab extension asset manifest.
		$mcp_extension_asset_path = \ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH . 'build/js/abilities/mcp-abilities-extension.asset.php';
		if ( file_exists( $mcp_extension_asset_path ) ) {
			$this->mcp_extension_asset_file = include $mcp_extension_asset_path;
		} elseif ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'acrossai-abilities-manager: build/js/abilities/mcp-abilities-extension.asset.php not found — run npm run build.' );
		}
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    0.0.1
	 * @param    string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_styles( string $hook_suffix ) {
		if ( ! $this->is_manager_page( $hook_suffix )
			&& ! $this->is_settings_page( $hook_suffix ) ) {
			return;
		}

		// Enqueue Abilities Manager styles only on main manager page (feature 011).
		if ( $this->abilities_asset_file && $this->is_manager_page( $hook_suffix ) ) {
			// wpb-access-control v1.2.0 ships compiled CSS only (no SCSS source); enqueue as dependency.
			$wpb_ac_base     = plugin_dir_path( \ACROSSAI_ABILITIES_MANAGER_PLUGIN_FILE ) . 'vendor/wpboilerplate/wpb-access-control/assets/build/';
			$wpb_ac_css_path = $wpb_ac_base . 'index.css';
			if ( file_exists( $wpb_ac_css_path ) ) {
				$wpb_ac_asset = file_exists( $wpb_ac_base . 'index.asset.php' )
					? require $wpb_ac_base . 'index.asset.php'
					: array( 'version' => '1.2.0' );
				wp_register_style(
					'acrossai-wpb-access-control',
					\ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL . 'vendor/wpboilerplate/wpb-access-control/assets/build/index.css',
					array(),
					$wpb_ac_asset['version']
				);
			}
			wp_register_style(
				'acrossai-abilities-manager-abilities',
				\ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL . 'build/css/abilities/abilities.css',
				file_exists( $wpb_ac_css_path ) ? array( 'acrossai-wpb-access-control' ) : array(),
				$this->abilities_asset_file['version']
			);
			wp_enqueue_style( 'acrossai-abilities-manager-abilities' );
		}
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    0.0.1
	 * @param    string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook_suffix ) {
		if ( ! $this->is_manager_page( $hook_suffix )
			&& ! $this->is_settings_page( $hook_suffix )
			&& ! $this->is_mcp_manager_abilities_tab() ) {
			return;
		}

		// Enqueue Abilities Manager scripts only on main manager page (feature 011).
		if ( $this->abilities_asset_file && $this->is_manager_page( $hook_suffix ) ) {
			wp_register_script(
				'acrossai-abilities-manager-abilities',
				\ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL . 'build/js/abilities/abilities.js',
				$this->abilities_asset_file['dependencies'],
				$this->abilities_asset_file['version'],
				true
			);
			wp_enqueue_script( 'acrossai-abilities-manager-abilities' );

			$data = array(
				'nonce'                      => wp_create_nonce( 'wp_rest' ),
				'rest_url'                   => untrailingslashit( rest_url() ),
				'rest_namespace'             => 'acrossai/v1',
				'current_user_id'            => get_current_user_id(),
				'perPage'                    => (int) get_option( 'acrossai_abilities_per_page', 20 ),
				// Client rendering gate only — server authorization enforced by wpb-ac/v1 REST endpoints (SEC-018-02).
				'access_control_available'   => \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Abilities_Access_Control::instance()->is_available(),
				// Issue #200: an ability with no rule is not unrestricted — it requires this capability.
				// The picker's own empty state reads "No user access added by admin", which would now be
				// actively misleading on its own, so the form states the floor above it.
				'default_ability_capability' => \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Ability_Override_Processor::default_capability(),
				// Per-consumer AC slug (wpb-access-control v2+) — the React <AccessControl> component
				// needs this to construct REST URLs like /wpb-ac/v1/{slug}/providers and /wpb-ac/v1/{slug}/rules/...
				// Source of truth: AcrossAI_Abilities_Access_Control::TABLE_SLUG.
				'access_control_slug'        => \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Abilities_Access_Control::TABLE_SLUG,
				'protected_slugs'            => \AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Protected_Abilities::get_protected_slugs(),
				// Feature 106 absorbed this entire tree into AcrossAI MCP Manager, so this
				// file now only ever runs as part of that plugin — the standalone plugin's
				// own copy of it is what runs on the rare pre-0.0.46 fallback path instead
				// (see acrossai-mcp-manager.php), and never reaches here. So this is not a
				// live is_plugin_active() check any more; it is unconditionally true
				// wherever this code executes. Kept as a key (rather than deleted) because
				// the "install MCP Manager" promo card in AbilityForm.jsx still branches on
				// it — see `!abilitiesConfig.mcp_manager_active` there.
				'mcp_manager_active'         => true,
				'mcp_manager_addons_url'     => admin_url( 'admin.php?page=acrossai-addons' ),
				'mcp_manager_info_url'       => 'https://acrossai.co/mcp-manager/',
			);

			/**
			 * Filter the abilities-manager admin localize data array (Feature 034 / FR-008).
			 *
			 * Subscribers MUST data-minimize — values appear in a browser-accessible JS global
			 * (window.acrossaiAbilitiesManager). Do NOT add secrets, tokens, hashed credentials,
			 * or user PII beyond what is strictly required for UI rendering. Prefer namespaced
			 * keys (e.g. acrossai_mcp_manager_*) to avoid collisions with future reserved keys.
			 *
			 * @since 0.0.1
			 * @param array $data Admin localize data injected as window.acrossaiAbilitiesManager.
			 */
			$data = apply_filters( 'acrossai_abilities_admin_localize_data', $data );

			wp_add_inline_script(
				'acrossai-abilities-manager-abilities',
				'window.acrossaiAbilitiesManager = ' . wp_json_encode( $data ) . ';',
				'before'
			);

			/**
			 * Fires after the abilities-manager admin script bundle is enqueued AND its
			 * localize data injected (Feature 034 / FR-007). Subscribers may use this to
			 * attach their own scripts to the 'acrossai-abilities-manager-abilities' handle.
			 *
			 * @since 0.0.1
			 */
			do_action( 'acrossai_abilities_form_settings_registered' );
		}

		// Feature 044: MCP Manager Abilities-tab extension. Appends the Action
		// column with a deep-link Edit button (URL scheme owned by Feature 043).
		// Depends on the sibling plugin's `acrossai-mcp-manager-abilities` handle
		// so ordering is enforced and enqueue is a silent no-op when the sibling
		// plugin is deactivated.
		if ( $this->mcp_extension_asset_file && $this->is_mcp_manager_abilities_tab() ) {
			$deps = array_merge(
				$this->mcp_extension_asset_file['dependencies'],
				array( 'acrossai-mcp-manager-abilities' )
			);
			wp_register_script(
				'acrossai-abilities-manager-mcp-extension',
				\ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL . 'build/js/abilities/mcp-abilities-extension.js',
				$deps,
				$this->mcp_extension_asset_file['version'],
				true
			);
			wp_add_inline_script(
				'acrossai-abilities-manager-mcp-extension',
				'window.acrossaiAbilitiesManagerMcpExtension = ' . wp_json_encode(
					array(
						'editBaseUrl' => admin_url( 'admin.php?page=acrossai-abilities-manager' ),
					)
				) . ';',
				'before'
			);
			wp_enqueue_script( 'acrossai-abilities-manager-mcp-extension' );
		}
	}

	/**
	 * Check if currently viewing the main Abilities Manager page.
	 *
	 * SC-011-04: Uses === strict comparison to prevent type-coercion bypass.
	 *
	 * @since    0.3.0
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return bool True if on main Abilities Manager page.
	 */
	private function is_manager_page( string $hook_suffix ): bool {
		// Feature 038: Abilities is no longer a top-level menu; it is a
		// submenu of the shared 'acrossai' parent. WordPress derives the
		// suffix as sanitize_title( parent_menu_title ) . '_page_' . menu_slug;
		// sanitize_title( 'AcrossAI' ) === 'acrossai' (lucky coincidence with
		// the parent slug). Verified at TASK-T021 pre-commit. See
		// memory-synthesis.md and BUG-LIBRARY-HOOK-SUFFIX scope note for
		// the fragility — if the host package renames its menu title, this
		// string MUST be updated.
		return 'acrossai_page_acrossai-abilities-manager' === $hook_suffix;
	}

	/**
	 * Checks whether the current admin screen is the settings page.
	 *
	 * @since    0.1.0
	 * @param string $hook_suffix The hook suffix for the current admin screen.
	 * @return bool
	 */
	private function is_settings_page( string $hook_suffix ): bool {
		// Feature 038: settings now live on the shared host page registered by
		// acrossai-co/main-menu under slug 'acrossai-settings' (host's
		// SettingsPage::SETTINGS_SLUG). The suffix derives from
		// sanitize_title( 'AcrossAI' ) === 'acrossai'. Verified at TASK-T021.
		return 'acrossai_page_acrossai-settings' === $hook_suffix;
	}

	/**
	 * Detect the acrossai-mcp-manager plugin's per-server Abilities tab.
	 *
	 * Feature 044: we cannot rely on a hook suffix because that page belongs
	 * to the sibling plugin's add_submenu_page() call. Instead we mirror the
	 * sibling plugin's own guard style (see acrossai-mcp-manager/admin/Main.php)
	 * and check the same three GET params it uses to gate its Abilities-tab
	 * bundle: `page=acrossai_mcp_manager` + `action=edit` + `tab=abilities`.
	 *
	 * @since 0.0.6
	 * @return bool True when on the sibling plugin's Abilities tab.
	 */
	private function is_mcp_manager_abilities_tab(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['action'], $_GET['tab'] ) ) {
			return false;
		}
		$page   = sanitize_key( wp_unslash( $_GET['page'] ) );
		$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		$tab    = sanitize_key( wp_unslash( $_GET['tab'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return 'acrossai_mcp_manager' === $page
			&& 'edit' === $action
			&& 'abilities' === $tab;
	}

	/**
	 * Add Settings link to plugins area
	 *
	 * @since 0.0.1
	 * @param array  $links Links array in which we would prepend our link.
	 * @param string $file  Current plugin basename.
	 * @return array
	 */
	public function plugin_action_links( $links, $file ) {
		if ( 'acrossai-abilities-manager/acrossai-abilities-manager.php' === $file ) {
			$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=acrossai-abilities-manager' ) ) . '">' . esc_html__( 'Settings', 'acrossai-abilities-manager' ) . '</a>';

			// Feature 099: the Plugins screen is where someone lands right after
			// activating, so it is the most likely place they go looking for
			// setup. Unshifted after Settings so the row reads
			// "Settings | Quick Connect via AcrossAI | Deactivate".
			//
			// Omitted on sites running AcrossAI MCP Manager, which offers its own
			// Quick Connect from its own row two lines up.
			if ( \AcrossAI_Abilities_Manager\Admin\Partials\QuickConnect\EntryPoints::is_available() ) {
				$quick_connect_link = '<a href="' . esc_url(
					\AcrossAI_Abilities_Manager\Admin\Partials\QuickConnect\EntryPoints::wizard_url()
				) . '">' . esc_html__( 'Quick Connect via AcrossAI', 'acrossai-abilities-manager' ) . '</a>';

				array_unshift( $links, $quick_connect_link );
			}

			array_unshift( $links, $settings_link );
		}
		return $links;
	}
}
