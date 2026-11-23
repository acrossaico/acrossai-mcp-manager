/**
 * Keeps `window.location.search` in lockstep with the `acrossai/abilities`
 * store's `view` state so the Edit page is deep-linkable, bookmarkable, and
 * navigable via browser back/forward.
 *
 * URL scheme:
 *   - list                              → ?page=acrossai-abilities-manager
 *   - { mode: 'edit', slug: 'ai/foo' }  → ?page=acrossai-abilities-manager&action=edit&slug=ai/foo
 *
 * `create` mode is intentionally not synced this round — no user-facing entry
 * point exists yet.
 *
 * @since 0.0.6
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { STORE_NAME } from '../store/index';
import { parseViewFromUrl, buildUrlFromView } from '../urlSync';

export { parseViewFromUrl, buildUrlFromView } from '../urlSync';

/**
 * React hook — call once at the top of the app root component.
 *
 * Three flows:
 *   1. On mount, read the URL and dispatch the matching `setView(…)` so a
 *      deep-link (e.g. shared/bookmarked edit URL) opens the correct view.
 *   2. On every `view` change, push a matching URL onto history (skip when
 *      the URL is already correct — avoids duplicate entries when the mount
 *      dispatch and the sync effect both fire on first render).
 *   3. On browser back/forward (`popstate`), re-parse the URL and dispatch
 *      the corresponding `setView(…)`.
 *
 * @return {void}
 */
export default function useUrlViewSync() {
	const view = useSelect( ( select ) => select( STORE_NAME ).getView(), [] );
	const dispatch = useDispatch( STORE_NAME );

	// Flow 1: mount-time deep-link parse.
	useEffect( () => {
		const initialView = parseViewFromUrl( window.location.href );
		if ( 'list' !== initialView ) {
			dispatch.setView( initialView );
		}
		// Intentionally run once on mount.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Flow 2: mirror store `view` → URL.
	useEffect( () => {
		const nextUrl = buildUrlFromView( view, window.location.href );
		if ( nextUrl === window.location.href ) {
			return;
		}
		window.history.pushState( {}, '', nextUrl );
	}, [ view ] );

	// Flow 3: browser back/forward — re-derive view from URL.
	useEffect( () => {
		const handler = () => {
			const nextView = parseViewFromUrl( window.location.href );
			dispatch.setView( nextView );
		};
		window.addEventListener( 'popstate', handler );
		return () => window.removeEventListener( 'popstate', handler );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
}
