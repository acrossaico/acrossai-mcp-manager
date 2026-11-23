/**
 * Shared constants for the abilities screen.
 *
 * @since 0.2.0
 */

/**
 * Sentinel for the "All" toolset tab.
 *
 * Salvaged from the retiring ability-library page in Feature 102. It is the value that means
 * "no toolset filter", and `buildUrlFromTab()` strips `?tab=` entirely when it sees it, so the
 * canonical URL for the default view stays clean.
 *
 * @type {string}
 */
export const ALL_TABS_KEY = '__all__';

/**
 * Largest page size the abilities list can serve.
 *
 * Mirrors the REST `per_page` `maximum` in `AcrossAI_Abilities_Read_Controller` and
 * `SettingsMenu::MAX_PER_PAGE`. Three places have to agree; when they did not, the settings screen
 * advertised 200, the sanitiser accepted it, and REST clamped to 100 with no error — so the list
 * showed 100 rows while the pager described a 200-row page size (issue #185).
 */
export const MAX_PER_PAGE = 100;
