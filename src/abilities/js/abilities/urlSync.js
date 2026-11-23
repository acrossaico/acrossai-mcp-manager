/**
 * Pure URL helpers for the abilities screen.
 *
 * Deliberately free of any store, component or apiFetch import so these can be unit-tested
 * directly (PATTERN-NAMED-EXPORT-JEST). The hook that consumes them lives in hooks/useUrlSync.js;
 * importing that instead pulls in the whole data store and the test cannot run.
 *
 * parseTabFromUrl() and buildUrlFromTab() are verbatim from the retiring useLibraryTabSync, so
 * their contracts — including the SEC-052-I-003 sentinel fallback — carry over unchanged.
 *
 * @since 0.2.0
 */
import { addQueryArgs, removeQueryArgs, getQueryArg } from '@wordpress/url';
import { ALL_TABS_KEY } from './constants';

/**
 * Parse a URL (or bare query string) into a `view` state value the store
 * accepts. Returns `'list'` unless the URL carries a supported action/slug
 * pair.
 *
 * Named export per PATTERN-NAMED-EXPORT-JEST.
 *
 * @param {string} url Any URL or `location.search`-style string.
 * @return {string|{mode:string, slug:string}} A value ready for `dispatch.setView(…)`.
 */
export function parseViewFromUrl( url ) {
	const source = url || '';
	const action = getQueryArg( source, 'action' );
	const slug = getQueryArg( source, 'slug' );

	if ( 'edit' === action && 'string' === typeof slug && '' !== slug ) {
		return { mode: 'edit', slug };
	}

	return 'list';
}

/**
 * Build the URL that represents the given `view`, preserving every other
 * query arg already on `currentUrl`. `action` and `slug` are owned by this
 * sync layer; `page` (WordPress admin routing key) and anything else pass
 * through verbatim.
 *
 * Named export per PATTERN-NAMED-EXPORT-JEST.
 *
 * @param {string|{mode:string, slug:string}} view       Current store view.
 * @param {string}                            currentUrl Current URL (typically `location.href`).
 * @return {string} A URL string with `action`/`slug` set to match the view.
 */
export function buildUrlFromView( view, currentUrl ) {
	const stripped = removeQueryArgs( currentUrl || '', 'action', 'slug' );

	if ( view && 'edit' === view.mode && view.slug ) {
		return addQueryArgs( stripped, { action: 'edit', slug: view.slug } );
	}

	return stripped;
}

/**
 * Parse a URL (or bare query string) into an active-tab identifier.
 *
 * Returns `allTabsKey` when the `tab` query arg is absent OR when the value is not present in
 * `validSlugs` — the SEC-052-I-003 sentinel-fallback contract. Never emit the raw URL value
 * downstream if it doesn't match a known tab.
 *
 * @param {string}   url        Any URL or `location.search`-style string.
 * @param {string[]} validSlugs Runtime list of registered tab identifiers.
 * @param {string}   allTabsKey The sentinel for the "All" tab.
 * @return {string} A tab identifier ready for `setActiveTab(…)`.
 */
export function parseTabFromUrl( url, validSlugs, allTabsKey ) {
	const raw = getQueryArg( url || '', 'tab' );
	if ( typeof raw !== 'string' || raw === '' ) {
		return allTabsKey;
	}
	if ( ! Array.isArray( validSlugs ) || ! validSlugs.includes( raw ) ) {
		return allTabsKey;
	}
	return raw;
}

/**
 * Build the URL representing `activeTab`, preserving every other query arg.
 *
 * When `activeTab === allTabsKey` the `tab` arg is stripped so the canonical default URL stays
 * clean. Other args pass through verbatim.
 *
 * @param {string} activeTab  Current active tab identifier or the sentinel.
 * @param {string} currentUrl Current URL (typically `location.href`).
 * @param {string} allTabsKey The sentinel for the "All" tab.
 * @return {string} URL string with `tab` set to match the active tab.
 */
export function buildUrlFromTab( activeTab, currentUrl, allTabsKey ) {
	const base = currentUrl || '';
	const stripped = removeQueryArgs( base, 'tab' );
	if ( activeTab === allTabsKey ) {
		return stripped;
	}
	return addQueryArgs( stripped, { tab: activeTab } );
}

/**
 * Compose both concerns into one URL.
 *
 * Order matters only in that both must be applied to the same starting href — which is the whole
 * point of merging the hooks.
 *
 * @param {string} view       Current store view.
 * @param {string} activeTab  Current active tab identifier.
 * @param {string} currentUrl Current URL.
 * @return {string} URL carrying both the view and the tab.
 */
export function buildUrl( view, activeTab, currentUrl ) {
	return buildUrlFromTab(
		activeTab,
		buildUrlFromView( view, currentUrl ),
		ALL_TABS_KEY,
	);
}
