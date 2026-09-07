<?php
/**
 * Shared admin page slugs for the MCP Manager menu structure.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 */

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the `?page=` slug used by the parent menu and
 * each submenu. Consumed by Menu (to register), by Settings + list tables
 * (to build URLs), and by Admin\Main (to whitelist `get_current_screen()`).
 *
 * Extracted from Menu::PAGE_SLUG per RT-1 (Architecture Review 2026-06-17)
 * to satisfy Constitution Module Contract item 3 — "Depend only on shared
 * utilities from `includes/Utilities/` — never on sibling modules directly".
 *
 * NOTE: This file deliberately does NOT use the singleton ceremony — it
 * exposes only `const` values. There is no instance state to share.
 */
final class AdminPageSlugs {

	/** Top-level menu page slug. Matches `?page=` URL value. */
	public const PARENT = 'acrossai_mcp_manager';

	/** Shared AcrossAI Settings page tab slug (kept in sync with SettingsMenu::TAB_SLUG). */
	public const SETTINGS_TAB = 'mcp';

	/**
	 * Screen IDs WordPress generates for our pages.
	 *
	 * Post-Feature-010 (2026-07-02), the plugin registers as SUBMENUS of the shared
	 * `acrossai` parent menu (owned by acrossai-co/main-menu package). WordPress
	 * derives the screen ID prefix from the parent menu *title* — for the shared
	 * parent it's `'AcrossAI'` → `sanitize_title()` → `'acrossai'`, producing
	 * `acrossai_page_<slug>` IDs.
	 *
	 * Legacy `toplevel_page_*` and `mcp-manager_page_*` IDs are retained ADDITIVELY
	 * per FR-022 / A9 — defense against multi-plugin ordering scenarios where an
	 * older `acrossai-co/main-menu` version wins jetpack-autoloader version
	 * resolution and our plugin re-registers as top-level. Never remove legacy IDs.
	 *
	 * Used by Admin\Main::is_plugin_admin_screen() for the asset-enqueue guard.
	 *
	 * @return string[]
	 */
	public static function plugin_screen_ids(): array {
		return array(
			// Post-Feature-010 submenu IDs (under shared `acrossai` parent).
			'acrossai_page_' . self::PARENT,
			// Shared Settings page (Feature 012 — MCP tab lives here).
			'acrossai_page_acrossai-settings',
			// Legacy top-level IDs (retained additively per A9).
			'toplevel_page_' . self::PARENT,
		);
	}

	/**
	 * Private constructor — constants-only utility, never instantiated.
	 */
	private function __construct() {
		// Never instantiated — constants-only utility.
	}
}
