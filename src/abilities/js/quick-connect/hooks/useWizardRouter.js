/**
 * Feature 099 — URL-driven wizard router.
 *
 * The URL is the source of truth (spec FR-014): every screen is linkable and
 * browser Back/Forward stay in step with the wizard's own controls.
 *
 *   const { step, method, goTo, advance, back, exit } = useWizardRouter();
 *
 * Ported from the sibling wizard. Three rules below are load-bearing — each one
 * fixes a bug that was diagnosed the hard way there. They are commented in place
 * so nobody "simplifies" them back into existence.
 *
 * @package
 */

import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { getQueryArg, addQueryArgs, removeQueryArgs } from '@wordpress/url';

/**
 * Every step id in navigation order.
 *
 * @type {string[]}
 */
export const STEP_ORDER = [ '1', '2', '3', '4', '5', '6', '7', 'done' ];

/**
 * Transport identifiers accepted in the URL.
 *
 * `setMethod` falls back to the default for anything not listed here, silently — so a card whose
 * value is missing from this array renders, highlights on click, and never actually selects.
 *
 * @type {string[]}
 */
export const METHODS = [ 'mcp-manager', 'mcp-adapter', 'elementor-mcp' ];

/**
 * Default transport — the recommended one, so its card is preselected (FR-020).
 *
 * @type {string}
 */
export const DEFAULT_METHOD = 'mcp-manager';

/**
 * Whether a step should be walked past given the current skip flags.
 *
 * Named export so the predicate is unit-testable without rendering
 * (PATTERN-NAMED-EXPORT-JEST).
 *
 * @param {string} step  Step id under consideration.
 * @param {Object} skips Skip flags from App.
 * @return {boolean} True when the step must not be shown.
 */
export const shouldSkip = ( step, skips = {} ) => {
	if ( step === '6' && skips.skipAdapterInstall ) {
		return true;
	}

	if ( step === '7' && skips.skipAdapterAbilities ) {
		return true;
	}

	return false;
};

/**
 * Read and validate wizard params from the URL.
 *
 * SEC-004: both values are validated against closed allowlists, not merely
 * sanitized. Anything unrecognised falls back to a default and is never
 * rendered, so a crafted URL cannot put arbitrary text on the screen.
 *
 * @return {{step: string, method: string}} Validated params.
 */
export const readParams = () => {
	const search =
		typeof window !== 'undefined' ? window.location.search || '' : '';

	const rawStep = getQueryArg( search, 'step' );
	const rawMethod = getQueryArg( search, 'method' );

	const step = STEP_ORDER.includes( String( rawStep ) ) ? String( rawStep ) : '1';
	const method = METHODS.includes( String( rawMethod ) )
		? String( rawMethod )
		: DEFAULT_METHOD;

	return { step, method };
};

/**
 * Build the next URL, preserving unrelated query args.
 *
 * `addQueryArgs` writes empty values as `key=`, which is visible noise, so an
 * absent method is removed rather than blanked.
 *
 * @param {string}      step   Destination step.
 * @param {string|null} method Transport identifier, or null to drop it.
 * @return {string} The next URL.
 */
const buildUrl = ( step, method ) => {
	let url = window.location.pathname + window.location.search;
	url = addQueryArgs( url, { step } );
	url = method
		? addQueryArgs( url, { method } )
		: removeQueryArgs( url, 'method' );
	return url;
};

/**
 * Same-tab navigation event.
 *
 * Native `popstate` does not fire for programmatic `pushState`, so without this
 * fan-out each mounted `useWizardRouter()` would keep independent state and a
 * `goTo()` called from anywhere other than App would silently no-op on the
 * render path.
 *
 * @type {string}
 */
const NAV_EVENT = 'acrossai-qc-nav';

const dispatchNav = () => {
	if ( typeof window !== 'undefined' && typeof CustomEvent === 'function' ) {
		window.dispatchEvent( new CustomEvent( NAV_EVENT ) );
	}
};

/**
 * Wizard router hook.
 *
 * @return {Object} Router API: step, method, goTo, setMethod, advance, back, exit.
 */
const useWizardRouter = () => {
	const [ params, setParams ] = useState( readParams );

	// Resync on browser Back/Forward and on same-tab navigation from any other
	// instance of this hook.
	useEffect( () => {
		const sync = () => setParams( readParams() );
		window.addEventListener( 'popstate', sync );
		window.addEventListener( NAV_EVENT, sync );
		return () => {
			window.removeEventListener( 'popstate', sync );
			window.removeEventListener( NAV_EVENT, sync );
		};
	}, [] );

	const goTo = useCallback( ( step, method = null ) => {
		if ( ! STEP_ORDER.includes( step ) ) {
			return;
		}

		// The history write MUST happen synchronously here, never inside the
		// setParams updater. React only runs an updater eagerly when the owning
		// fiber has no queued work; with work pending it defers it. dispatchNav()
		// fires immediately either way, so a deferred updater made NAV_EVENT
		// listeners read the OLD URL and queue a second update that landed after
		// this one — silently reverting the navigation. That was the sibling's
		// "Continue needs two clicks" bug.
		const current = readParams();
		const nextMethod = method || current.method;
		const nextUrl = buildUrl( step, nextMethod );

		window.history.pushState( {}, '', nextUrl );
		setParams( { step, method: nextMethod } );
		dispatchNav();
	}, [] );

	/**
	 * Change the selected transport without changing step.
	 *
	 * Uses replaceState: picking a card is a refinement of the current screen,
	 * not a new destination, so Back should still leave the screen rather than
	 * undo the selection.
	 */
	const setMethod = useCallback( ( method ) => {
		const next = METHODS.includes( method ) ? method : DEFAULT_METHOD;
		const current = readParams();

		if ( current.method === next ) {
			return; // No-op: avoids a redundant history write and re-render.
		}

		const nextUrl = buildUrl( current.step, next );
		window.history.replaceState( {}, '', nextUrl );
		setParams( { ...current, method: next } );
		dispatchNav();
	}, [] );

	const advance = useCallback(
		( { skips = {} } = {} ) => {
			const idx = STEP_ORDER.indexOf( params.step );

			if ( idx === -1 || idx >= STEP_ORDER.length - 1 ) {
				return;
			}

			let nextIdx = idx + 1;

			// Walk past every consecutive skipped step so chained skips resolve in
			// one hop rather than flashing an intermediate screen.
			while (
				nextIdx < STEP_ORDER.length - 1 &&
				shouldSkip( STEP_ORDER[ nextIdx ], skips )
			) {
				nextIdx += 1;
			}

			goTo( STEP_ORDER[ nextIdx ] );
		},
		[ params.step, goTo ],
	);

	const back = useCallback(
		( { skips = {} } = {} ) => {
			const idx = STEP_ORDER.indexOf( params.step );

			if ( idx <= 0 ) {
				return;
			}

			let prevIdx = idx - 1;

			while ( prevIdx > 0 && shouldSkip( STEP_ORDER[ prevIdx ], skips ) ) {
				prevIdx -= 1;
			}

			goTo( STEP_ORDER[ prevIdx ] );
		},
		[ params.step, goTo ],
	);

	const exit = useCallback( () => {
		const bootstrap = window.acrossaiQuickConnect || {};
		window.location.href =
			bootstrap.adminUrl ||
			'/wp-admin/admin.php?page=acrossai-abilities-manager';
	}, [] );

	// Memoize the returned object. A fresh literal per render gave `router` a new
	// identity every time, which cascaded through App's guard context into
	// useFooterAction's effect and became a self-sustaining passive-effect update
	// loop ("Maximum update depth exceeded") in the sibling.
	return useMemo(
		() => ( {
			step: params.step,
			method: params.method,
			goTo,
			setMethod,
			advance,
			back,
			exit,
		} ),
		[ params.step, params.method, goTo, setMethod, advance, back, exit ],
	);
};

export default useWizardRouter;
