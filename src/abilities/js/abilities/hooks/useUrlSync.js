/**
 * URL synchronisation for the abilities screen.
 *
 * Replaces useUrlViewSync + useLibraryTabSync, which Feature 102 merged for a concrete reason:
 * both wrote history with `pushState( build( …, window.location.href ) )`. Run in the same tick —
 * opening an ability while a toolset is selected, say — each read a stale `href` and the second
 * clobbered the first, so one of the two query args silently vanished. One hook, one pushState.
 *
 * `?tab=` deliberately survives `?action=edit`, so Back from the edit form returns to the toolset
 * the operator was on rather than dumping them in "All".
 *
 * parseTabFromUrl() and buildUrlFromTab() are salvaged verbatim from useLibraryTabSync so their
 * existing unit tests keep testing the same functions — including the SEC-052-I-003 sentinel
 * contract, which must not regress: an unrecognised `?tab=` value falls back to ALL_TABS_KEY and
 * is never passed downstream raw.
 *
 * @since 0.2.0
 */
import { useEffect, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store/index';
import { ALL_TABS_KEY } from '../constants';
import { parseViewFromUrl } from './useUrlViewSync';
import { parseTabFromUrl, buildUrl } from '../urlSync';

export { parseTabFromUrl, buildUrlFromTab, buildUrl } from '../urlSync';

/**
 * React hook — call once from AbilitiesManager.
 *
 * @param {string[]} validSlugs Runtime list of toolset identifiers.
 * @return {void}
 */
export default function useUrlSync( validSlugs ) {
	const view = useSelect( ( select ) => select( STORE_NAME ).getView(), [] );
	const activeTab = useSelect(
		( select ) => select( STORE_NAME ).getActiveTab(),
		[],
	);
	const dispatch = useDispatch( STORE_NAME );

	// The toolset list arrives asynchronously, so on first render validSlugs is empty and
	// parseTabFromUrl() cannot tell `?tab=cache` from `?tab=nonsense` — the SEC-052-I-003 sentinel
	// contract correctly rejects both. The incoming href is therefore captured before anything can
	// rewrite it, and the tab is applied once there is a list to validate against. Without this the
	// URL mirror below strips `?tab=` on load and every shared or bookmarked toolset link opens on
	// "All".
	const initialHref = useRef(
		'undefined' === typeof window ? '' : window.location.href,
	);
	const tabApplied = useRef( false );
	const ready = Array.isArray( validSlugs ) && validSlugs.length > 0;

	// Flow 1: mount-time view parse. The view needs no allow-list, so it resolves immediately.
	useEffect( () => {
		const initialView = parseViewFromUrl( initialHref.current );
		if ( 'list' !== initialView ) {
			dispatch.setView( initialView );
		}
		// Intentionally run once on mount.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Flow 1b: apply the deep-linked toolset as soon as it can be validated.
	useEffect( () => {
		if ( ! ready || tabApplied.current ) {
			return;
		}
		tabApplied.current = true;
		const initialTab = parseTabFromUrl(
			initialHref.current,
			validSlugs,
			ALL_TABS_KEY,
		);
		if ( ALL_TABS_KEY !== initialTab ) {
			dispatch.setActiveTab( initialTab );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ ready ] );

	// Flow 2: mirror store → URL. One pushState covering both args; this is the merge.
	//
	// Held back until the toolset list is known. Running earlier would rewrite the URL from a store
	// that has not yet had the chance to read it, discarding the deep link it is supposed to mirror.
	useEffect( () => {
		if ( ! ready ) {
			return;
		}
		const nextUrl = buildUrl( view, activeTab, window.location.href );
		if ( nextUrl === window.location.href ) {
			return;
		}
		window.history.pushState( {}, '', nextUrl );
	}, [ view, activeTab, ready ] );

	// Flow 3: browser back/forward — re-derive both from the URL.
	useEffect( () => {
		const handler = () => {
			const href = window.location.href;
			dispatch.setView( parseViewFromUrl( href ) );
			dispatch.setActiveTab(
				parseTabFromUrl( href, validSlugs, ALL_TABS_KEY ),
			);
		};
		window.addEventListener( 'popstate', handler );
		return () => window.removeEventListener( 'popstate', handler );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
}
