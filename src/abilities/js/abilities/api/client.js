/**
 * REST API client for the Custom Abilities Manager.
 *
 * All requests use @wordpress/api-fetch with the WP REST nonce set at app init.
 * getAbilities uses parse:false to access X-WP-Total / X-WP-TotalPages headers.
 *
 * @since 0.2.0
 */
import apiFetch from '@wordpress/api-fetch';

// Defensive: a bare destructure throws at module-evaluation time if wp_localize_script has not
// run, which takes the entire admin bundle down at import rather than degrading. It also made
// this module impossible to import from a unit test (see #183).
const { rest_namespace: restNamespace } = window.acrossaiAbilitiesManager || {};
const BASE = `${ restNamespace }/abilities`;

/**
 * Fetch paginated, filtered abilities list.
 * Returns total + pages from REST response headers.
 *
 * @param {Object} params Query params (page, per_page, search, orderby, order, source, status).
 * @return {Promise<{abilities: Array, total: number, pages: number}>} The page of abilities, plus the total row and page counts read from the response headers.
 */
export async function getAbilities( params = {} ) {
	const qs = new URLSearchParams();
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( null !== value && undefined !== value && '' !== value ) {
			qs.set( key, String( value ) );
		}
	} );

	const queryString = qs.toString();
	const path = `${ BASE }${ queryString ? '?' + queryString : '' }`;

	const response = await apiFetch( { path, parse: false } );

	if ( ! response.ok ) {
		let message = `Server error: ${ response.status } ${ response.statusText }`;
		try {
			const errData = await response.clone().json();
			if ( errData?.message ) {
				message = errData.message;
			}
		} catch {
			// Non-JSON error body — keep the status message.
		}
		throw new Error( message );
	}

	const data = await response.json();

	return {
		abilities: Array.isArray( data ) ? data : [],
		total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
		pages: parseInt( response.headers.get( 'X-WP-TotalPages' ) || '1', 10 ),
	};
}

/**
 * Fetch a single ability by slug.
 *
 * @param {string} slug Ability slug.
 * @return {Promise<Object>} The ability record.
 */
export async function getAbility( slug ) {
	return apiFetch( { path: `${ BASE }/${ encodeURIComponent( slug ) }` } );
}

/**
 * Create a new custom ability.
 *
 * @param {Object} data Ability fields.
 * @return {Promise<Object>} The newly created ability record.
 */
export async function createAbility( data ) {
	return apiFetch( { path: BASE, method: 'POST', data } );
}

/**
 * Sparsely update an existing ability (only send changed fields).
 *
 * @param {string} slug Ability slug.
 * @param {Object} data Changed fields only.
 * @return {Promise<Object>} The updated ability record.
 */
export async function updateAbility( slug, data ) {
	return apiFetch( {
		path: `${ BASE }/${ encodeURIComponent( slug ) }`,
		method: 'POST',
		data,
	} );
}

/**
 * Delete an ability by slug.
 *
 * @param {string} slug Ability slug.
 * @return {Promise<void>}
 */
export async function deleteAbility( slug ) {
	return apiFetch( {
		path: `${ BASE }/${ encodeURIComponent( slug ) }`,
		method: 'DELETE',
	} );
}

/**
 * Delete the override row for a non-db (plugin/core/theme) ability,
 * restoring it to its registry-declared defaults.
 *
 * @param {string} slug Ability slug.
 * @return {Promise<Object>} Fresh merged ability data (registry values, no overrides).
 */
export async function deleteOverride( slug ) {
	return apiFetch( {
		path: `${ BASE }/${ encodeURIComponent( slug ) }/override`,
		method: 'DELETE',
	} );
}

/**
 * Fetch ability categories.
 *
 * @return {Promise<Array<{slug: string, label: string}>>} Every ability category, as slug/label pairs.
 */
export async function getCategories() {
	return apiFetch( { path: `${ BASE }/categories` } );
}

/**
 * Fetch the ability count per toolset.
 *
 * Served from this namespace rather than localised into the page on purpose: the override
 * processor prunes site_allowed=false abilities from the registry on every request except this
 * one, so a count taken during page render would omit exactly the blocked abilities the screen
 * then lists (BUG-PATH-B-AGGREGATE-UNDERCOUNT).
 *
 * @return {Promise<Object>} Map of tab_group => count.
 */
export async function getToolsetCounts() {
	return apiFetch( { path: `${ BASE }/toolsets` } );
}

/**
 * Set the wpb-access-control rule for a single ability slug (Feature 056).
 *
 * Delegates to the composer-provided PUT endpoint under the plugin's
 * access_control_slug namespace. Sending `acKey=''` clears the rule
 * (equivalent to "Everyone allowed").
 *
 * @param {string}   slug      Ability slug.
 * @param {string}   acKey     Provider id (e.g. 'wp_role') or '' to clear.
 * @param {string[]} acOptions Provider-specific options; empty when clearing.
 * @return {Promise<Object>} Composer response body.
 */
export async function setAccessControlRule( slug, acKey, acOptions ) {
	const cfg = window.acrossaiAbilitiesManager || {};
	if ( ! cfg.access_control_slug ) {
		throw new Error(
			'Access control is not configured on this site (access_control_slug missing).',
		);
	}
	// The ability slug contains a literal '/' (e.g. "acrossai/foo")
	// which the composer route's `(?P<key>.+)` regex greedy-matches. Do NOT
	// encodeURIComponent the slug — WordPress's REST layer + the composer's
	// sanitizer strip %2F rather than decoding it, producing a corrupt key
	// (observed: "acrossai-abilities-managerblock-pattern-delete"). Matches
	// AbilityForm.jsx per-row save at line ~555 which passes the raw slug.
	// Slugs are server-sanitized to [a-z0-9-/]+ by sanitize_ability_slug (SEC-01),
	// so no path-traversal risk from raw pass-through.
	const path = `/wpb-ac/v1/${ encodeURIComponent( cfg.access_control_slug ) }/rules/acrossai-abilities/${ slug }`;
	return apiFetch( {
		path,
		method: 'PUT',
		data: { ac_key: acKey, ac_options: acOptions },
	} );
}
