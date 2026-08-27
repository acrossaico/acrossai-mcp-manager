<?php
/**
 * MCP Manager admin menu — top-level + submenus + plugin action link.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;
use AcrossAI_Main_Menu\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's admin surface as SUBMENUS under the shared `acrossai`
 * top-level menu (owned by the acrossai-co/main-menu package, namespace
 * \AcrossAI_Main_Menu\). Submenus: MCP Manager (main) and Access Control
 * (conditional). Also registers the "Settings" plugin-action link on the
 * Plugins screen.
 *
 * Per FR-018 / FR-019 / FR-020 / FR-021 (Feature 010, 2026-07-02).
 *
 * URL slug `acrossai_mcp_manager` is PRESERVED (CONSTRAINT 5 / FR-019) — submenu
 * URLs resolve identically to top-level URLs (?page=<slug>).
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter.
 * Hooks wired by Includes\Main::define_admin_hooks() targeting register_submenu().
 *
 * Page slugs live in Includes\Utilities\AdminPageSlugs (RT-1, 2026-06-17)
 * so list tables and Settings don't depend on this sibling for constants.
 */
class Menu {

	/** @var Menu|null */
	protected static $_instance = null;

	/** @var string */
	private $plugin_name;

	/** @var string */
	private $version;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->plugin_name = ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG;
		$this->version     = ACROSSAI_MCP_MANAGER_VERSION;
	}

	/**
	 * Register submenus under the shared `acrossai` parent menu (FR-018 / FR-020).
	 *
	 * Positions per FR-020 (avoiding collision with acrossai-abilities-manager's
	 * position 1 for Abilities):
	 *   - Position 2 — MCP Manager main (Servers listing)
	 *   - Position 4 — Access Control (conditional on vendor package presence)
	 *
	 * Render callbacks delegate to Settings::instance() so that Settings
	 * owns all page rendering and stays the single source of truth for
	 * admin HTML (Constitution Admin Partials Rule).
	 *
	 * The shared parent menu itself is bootstrapped by \AcrossAI_Main_Menu\SettingsPage
	 * in acrossai-mcp-manager.php on plugins_loaded priority 0 (FR-029 / D15 / DEV4).
	 */
	public function register_submenu(): void {
		$settings = Settings::instance();

		// 1) MCP main page — position 2 under `acrossai` parent (FR-020).
		add_submenu_page(
			SettingsPage::PARENT_SLUG,
			__( 'MCP', 'acrossai-mcp-manager' ),
			__( 'MCP', 'acrossai-mcp-manager' ),
			'manage_options',
			AdminPageSlugs::PARENT,
			array( $settings, 'render_list_page' ),
			2
		);

		// 2) Quick Connect via AcrossAI — position 3, right after MCP (F072 FR-003).
		// URL-literal menu_slug + empty render callback = WP renders this
		// submenu item as a direct link to the wizard URL; no dedicated
		// page is created because ?quick-connect=1 is handled by the MCP
		// page's own dispatcher (Settings::render_list_page).
		add_submenu_page(
			SettingsPage::PARENT_SLUG,
			__( 'Quick Connect via AcrossAI', 'acrossai-mcp-manager' ),
			__( 'Quick Connect via AcrossAI', 'acrossai-mcp-manager' ),
			'manage_options',
			'admin.php?page=' . AdminPageSlugs::PARENT . '&quick-connect=1&step=1',
			'',
			3
		);
	}

	/**
	 * Prepend a "Settings" action link to the plugin's row on the Plugins screen.
	 * Wired via `plugin_action_links_<basename>` so this callback only fires
	 * for this plugin (FR-003).
	 *
	 * S5/B6: admin_url() output is wrapped with esc_url() at the boundary.
	 *
	 * @param array<int|string, string> $links Existing action links.
	 * @return array<int|string, string> Modified links.
	 */
	public function plugin_action_links( $links ): array {
		// Settings link now targets the shared AcrossAI Settings page with the
		// MCP tab preselected. The tab body's sub-nav (Servers / Settings /
		// Quick Connect via AcrossAI) lives in SettingsMenu::render_subnav.
		$settings_url  = esc_url(
			admin_url(
				'admin.php?page=' . SettingsPage::SETTINGS_SLUG
				. '&tab=' . SettingsMenu::TAB_SLUG
			)
		);
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			$settings_url,
			esc_html__( 'Settings', 'acrossai-mcp-manager' )
		);

		$quick_connect_url  = esc_url(
			admin_url( 'admin.php?page=' . AdminPageSlugs::PARENT . '&quick-connect=1&step=1&server=1' )
		);
		$quick_connect_link = sprintf(
			'<a href="%s">%s</a>',
			$quick_connect_url,
			esc_html__( 'Quick Connect via AcrossAI', 'acrossai-mcp-manager' )
		);

		// Prepend Quick Connect via AcrossAI first, then Settings, so the final row order
		// reads Settings | Quick Connect via AcrossAI | Deactivate | Download (F072 FR-002).
		array_unshift( $links, $quick_connect_link );
		array_unshift( $links, $settings_link );

		return $links;
	}
}
