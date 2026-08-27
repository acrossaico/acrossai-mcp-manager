/**
 * F069 — URL-driven wizard router hook.
 *
 * Contract from `contracts/react-router.md`:
 *   const { step, method, goTo, advance, back, exit } = useWizardRouter();
 *
 * - `step` and `method` are strings read from window.location.search via
 *   @wordpress/url `getQueryArg`. Source of truth is the URL (FR-008).
 * - `goTo`, `advance`, `back` write via `history.pushState` + `addQueryArgs`
 *   — no full page reload. Popstate listener keeps state in sync with
 *   browser Back/Forward navigation.
 * - `exit` navigates (full nav) to the plugin's list-table URL (from
 *   window.acrossaiMcpQuickConnect.adminUrl).
 * - `advance`/`back` respect Step 4 auto-skip when `enabled=true` is
 *   passed in via the second arg (delegates the decision to the caller —
 *   the hook doesn't know about wizardState, keeps concerns separate).
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { getQueryArg, addQueryArgs, removeQueryArgs } from '@wordpress/url';

const STEP_ORDER = [
	'1', '2', '3', '4', '5', '6', '7',
	'8', '9', '10', '11', '12', '13',
	'done',
];

/**
 * Conditional steps skipped depending on wizard state:
 *   - 2  (server create) — skip unless user chose "Create new" in step 1
 *   - 4  (abilities-manager gate) — skip when plugin is already active
 *   - 5  (abilities picker) — skip when all abilities are already enabled
 *   - 6  (enable endpoint) — skip when server is already enabled
 *   - 8  (Pro pitch) — skip unless method=connectors AND pro state=missing
 *   - 9  (Pro install/activate/licence) — skip unless method=connectors AND
 *     Pro is not yet both active and licensed
 *   - 10 (Connectors detail) — skip unless method=connectors AND pro state=active
 *     AND a licence is connected
 *   - 11 (Client detail) — skip unless method=client
 *   - 12 (npm detail) — skip unless method=npm
 *   - 13 (WP-CLI detail) — skip unless method=wpcli
 *
 * Callers pass a `skips` object with matching boolean flags to advance/back so
 * the router can walk past unwanted steps in either direction. The hook stays
 * ignorant of wizardState — that lookup belongs in App.jsx.
 */
const shouldSkip = ( step, skips ) => {
	if ( step === '9' && skips.skipProSetup ) return true;
	if ( step === '2' && skips.skipCreate ) return true;
	if ( step === '4' && skips.skipAbilitiesGate ) return true;
	if ( step === '5' && skips.skipAbilities ) return true;
	if ( step === '6' && skips.skipEnable ) return true;
	if ( step === '8' && skips.skipProPromo ) return true;
	if ( step === '10' && skips.skipConnectorsDetail ) return true;
	if ( step === '11' && skips.skipClient ) return true;
	if ( step === '12' && skips.skipNpm ) return true;
	if ( step === '13' && skips.skipWpcli ) return true;
	return false;
};

const readParams = () => {
	const search = window.location.search || '';
	// getQueryArg returns undefined for missing params; normalize to null/string.
	const rawStep = getQueryArg( search, 'step' );
	const rawMethod = getQueryArg( search, 'method' );
	const rawMode = getQueryArg( search, 'mode' );
	const rawServer = getQueryArg( search, 'server' );
	const parsedServer = rawServer ? parseInt( rawServer, 10 ) : NaN;
	return {
		step: ( rawStep === undefined || rawStep === '' ) ? '1' : String( rawStep ),
		method: ( rawMethod === undefined || rawMethod === '' ) ? null : String( rawMethod ),
		mode: ( rawMode === undefined || rawMode === '' ) ? null : String( rawMode ),
		server: Number.isFinite( parsedServer ) && parsedServer > 0 ? parsedServer : null,
	};
};

const buildUrl = ( step, method, mode, server ) => {
	// Preserve existing query params (page, quick-connect, server) — only
	// mutate step + method + mode + server.
	//
	// IMPORTANT: `addQueryArgs` writes empty strings as `key=` (visible noise
	// in the URL). Use `removeQueryArgs` to drop absent optional params instead.
	let url = window.location.pathname + window.location.search;
	url = addQueryArgs( url, { step } );
	url = method
		? addQueryArgs( url, { method } )
		: removeQueryArgs( url, 'method' );
	url = mode
		? addQueryArgs( url, { mode } )
		: removeQueryArgs( url, 'mode' );
	url = server
		? addQueryArgs( url, { server } )
		: removeQueryArgs( url, 'server' );
	return url;
};

// Custom navigation event fired after any `pushState` / `replaceState` inside
// this hook. Every mounted instance re-syncs its local `params` state from
// the URL on this event, so a call to `router.goTo('1')` inside Completion
// also updates App.jsx's router state → App re-renders with the new step
// component. Without this fan-out, each `useWizardRouter()` call site would
// maintain independent state and only browser Back/Forward (which fires
// native `popstate`) would keep them in sync — a `router.goTo(...)` from
// any component that isn't App silently no-ops on the render path.
const NAV_EVENT = 'acrossai-mcp-qs-nav';

const dispatchNav = () => {
	if ( typeof window !== 'undefined' && typeof CustomEvent === 'function' ) {
		window.dispatchEvent( new CustomEvent( NAV_EVENT ) );
	}
};

const useWizardRouter = () => {
	const [ params, setParams ] = useState( readParams );

	// Sync on browser Back/Forward AND on same-tab `pushState`/`replaceState`
	// dispatched by any other instance of this hook (see NAV_EVENT above).
	useEffect( () => {
		const sync = () => setParams( readParams() );
		window.addEventListener( 'popstate', sync );
		window.addEventListener( NAV_EVENT, sync );
		return () => {
			window.removeEventListener( 'popstate', sync );
			window.removeEventListener( NAV_EVENT, sync );
		};
	}, [] );

	// `mode` is normally cleared on step navigation (it's a per-step sub-view),
	// but a caller can seed the destination's mode in the same hop — Step 8's
	// trial handoff uses goTo( '9', null, 'trial' ) so Step 9 knows the
	// operator arrived from a completed checkout rather than plain Continue.
	// Passing it here (rather than a follow-up setMode) keeps it to ONE
	// history entry, so Back still takes one press.
	const goTo = useCallback( ( step, method = null, mode = null ) => {
		if ( ! STEP_ORDER.includes( step ) ) {
			return;
		}
		// ⚠️  The history write MUST happen synchronously HERE — never inside
		// the setParams updater. React only runs an updater eagerly when the
		// owning fiber has no queued work; with work pending it defers the
		// updater to the next render. `dispatchNav()` below fires immediately
		// either way, so a deferred updater meant NAV_EVENT listeners ran
		// `setParams( readParams() )` against the OLD URL and queued a second
		// update that landed AFTER this one — silently reverting the
		// navigation. That was the "Save and Continue needs two clicks" bug on
		// Step 3: the URL advanced but the rendered step stayed put.
		//
		// Reading the current params from the URL (not from React state) is
		// consistent with FR-008 — the URL is the source of truth.
		//
		// Preserve the current server param so it survives navigation.
		// `mode` is normally null (per-step sub-view is cleared on step
		// change) but Step 8 → Step 9 handoff seeds `mode=trial` in the
		// same hop to keep it to one history entry.
		const current = readParams();
		const nextUrl = buildUrl( step, method, mode, current.server );
		window.history.pushState( {}, '', nextUrl );
		setParams( { step, method, mode, server: current.server } );
		dispatchNav();
	}, [] );

	// Set/clear a per-step sub-view mode (e.g. `?mode=create` for Step 1's
	// inline server-create form). URL-backed so browser Back returns to the
	// picker instead of walking all the way out of Step 1.
	const setMode = useCallback( ( mode ) => {
		// Same synchronous-history-write rule as goTo above.
		const current = readParams();
		const next = { ...current, mode: mode || null };
		const nextUrl = buildUrl( current.step, current.method, next.mode, current.server );
		window.history.pushState( {}, '', nextUrl );
		setParams( next );
		dispatchNav();
	}, [] );

	// Sync server id into the URL. Used by App.jsx whenever wizardState's
	// server_id changes (e.g. after Step 1 pick or Step 2 create) so that
	// steps 2+ carry `?server=<id>` for shareable deep-links. `replaceState`
	// (not push) so we don't clutter the browser history with server-id-only
	// changes — the step navigation entries are what belong in history.
	const setServer = useCallback( ( server ) => {
		// Same synchronous-history-write rule as goTo above.
		const normalized =
			Number.isFinite( server ) && server > 0 ? server : null;
		const current = readParams();
		if ( current.server === normalized ) {
			return; // no-op — avoids an extra history entry / rerender.
		}
		const nextUrl = buildUrl( current.step, current.method, current.mode, normalized );
		window.history.replaceState( {}, '', nextUrl );
		setParams( { ...current, server: normalized } );
		dispatchNav();
	}, [] );

	const advance = useCallback( ( { skips = {} } = {} ) => {
		const idx = STEP_ORDER.indexOf( params.step );
		if ( idx === -1 || idx >= STEP_ORDER.length - 1 ) {
			return;
		}
		let nextIdx = idx + 1;
		// Walk past every consecutive step whose skip predicate is truthy —
		// e.g. Step 3 → Step 5 when abilities-manager is active AND server
		// is enabled would jump 3 → 4 (skipped) → 5 (skipped) → 6? No, 6 is
		// enable, also skipped → 7. The while-loop handles all such chains.
		while (
			nextIdx < STEP_ORDER.length - 1 &&
			shouldSkip( STEP_ORDER[ nextIdx ], skips )
		) {
			nextIdx += 1;
		}
		goTo( STEP_ORDER[ nextIdx ], null );
	}, [ params.step, goTo ] );

	const back = useCallback( ( { skips = {} } = {} ) => {
		const idx = STEP_ORDER.indexOf( params.step );
		if ( idx <= 0 ) {
			return;
		}
		let prevIdx = idx - 1;
		while ( prevIdx > 0 && shouldSkip( STEP_ORDER[ prevIdx ], skips ) ) {
			prevIdx -= 1;
		}
		goTo( STEP_ORDER[ prevIdx ], null );
	}, [ params.step, goTo ] );

	const exit = useCallback( () => {
		const bootstrap = window.acrossaiMcpQuickConnect || {};
		const target = bootstrap.adminUrl || '/wp-admin/admin.php?page=acrossai_mcp_manager';
		window.location.href = target;
	}, [] );

	// ⚠️  Memoize the returned object. A fresh object literal per render gave
	// `router` a new identity every time, which cascaded:
	//   App:   advanceFromContext( [router, skips] ) → new every render
	//          → guardContext → new every render
	//   Step:  handleSaveAndContinue( [..., advance] ) → new every render
	//          → footerAction → new every render
	//          → useFooterAction's effect ( [action] ) re-fires every render
	//          → setFooterAction( newObject ) on App's fiber → re-render → …
	// a self-sustaining passive-effect update loop (React logs "Maximum
	// update depth exceeded"). It also kept App's fiber permanently dirty,
	// which is what made goTo's deferred-updater bug above fire reliably on
	// every step that registers a footer action (3, 5, 6).
	return useMemo(
		() => ( {
			step: params.step,
			method: params.method,
			mode: params.mode,
			server: params.server,
			goTo,
			setMode,
			setServer,
			advance,
			back,
			exit,
		} ),
		[
			params.step,
			params.method,
			params.mode,
			params.server,
			goTo,
			setMode,
			setServer,
			advance,
			back,
			exit,
		]
	);
};

export default useWizardRouter;
