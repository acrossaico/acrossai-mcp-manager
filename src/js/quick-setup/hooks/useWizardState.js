/**
 * F069 — Wizard state store (reducer + Context + apiFetch integration).
 *
 * Contract from `contracts/wizard-state.md`:
 *   const {
 *     state, isLoading, error,
 *     refetch, saveStep, complete,
 *   } = useWizardState();
 *
 * State shape mirrors contracts/wizard-state.md § Store shape.
 *
 * Optimistic updates: `saveStep` merges the delta into wizardState BEFORE
 * the REST round-trip completes. On failure, dispatch SAVE_STEP_ERROR with
 * the pre-write snapshot as rollbackState; reducer reverts.
 *
 * SECURITY: apiFetch nonce middleware is wired at the entry-file level
 * (src/js/quick-setup.js — TASK-SEC-005 / SEC-005). This hook consumes the
 * middleware transparently. 403 responses surface the "session expired"
 * message per the same task's user-friendly error contract.
 *
 * @package AcrossAI_MCP_Manager
 */

import { createContext, useContext, useReducer, useCallback, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

// ─────────────────────────────────────────────────────────────────────────
// Initial state + action types
// ─────────────────────────────────────────────────────────────────────────

const initialState = {
	status: 'idle', // 'idle' | 'loading' | 'ready' | 'saving' | 'error'
	error: null,

	servers: [],
	abilities: {
		total: 0,
		enabledForServer: null,
		hasManagerPlugin: false,
	},
	plugins: {
		acrossaiPro: 'missing',
		// F074 — defaults false so the wizard never walks past the Pro
		// licence gate on a payload that predates this field.
		acrossaiProLicensed: false,
		abilitiesManager: 'missing',
	},
	methods: {
		npm: [],
		clients: [],
		ai_connectors: [],
	},

	wizardState: {
		current_step: 1,
		server_id: null,
		access_saved: false,
		abilities_saved: false,
		enabled: false,
		method: null,
		created_at: null,
	},
};

const ACTION = {
	HYDRATE_START: 'HYDRATE_START',
	HYDRATE_SUCCESS: 'HYDRATE_SUCCESS',
	HYDRATE_ERROR: 'HYDRATE_ERROR',
	SAVE_STEP_START: 'SAVE_STEP_START',
	SAVE_STEP_SUCCESS: 'SAVE_STEP_SUCCESS',
	SAVE_STEP_ERROR: 'SAVE_STEP_ERROR',
	COMPLETE_START: 'COMPLETE_START',
	COMPLETE_SUCCESS: 'COMPLETE_SUCCESS',
	CLEAR_ERROR: 'CLEAR_ERROR',
};

// ─────────────────────────────────────────────────────────────────────────
// Reducer
// ─────────────────────────────────────────────────────────────────────────

const reducer = ( state, action ) => {
	switch ( action.type ) {
		case ACTION.HYDRATE_START:
			return { ...state, status: 'loading', error: null };

		case ACTION.HYDRATE_SUCCESS: {
			const p = action.payload || {};
			return {
				...state,
				status: 'ready',
				error: null,
				servers: Array.isArray( p.servers ) ? p.servers : [],
				abilities: p.abilities || state.abilities,
				plugins: p.plugins || state.plugins,
				methods: p.methods || state.methods,
				wizardState: { ...state.wizardState, ...( p.wizardState || {} ) },
			};
		}

		case ACTION.HYDRATE_ERROR:
			return { ...state, status: 'error', error: action.payload };

		case ACTION.SAVE_STEP_START: {
			// Optimistic scratchpad merge — apply the delta before REST resolves.
			return {
				...state,
				status: 'saving',
				error: null,
				wizardState: { ...state.wizardState, ...( action.optimistic || {} ) },
			};
		}

		case ACTION.SAVE_STEP_SUCCESS: {
			const p = action.payload || {};
			return {
				...state,
				status: 'ready',
				error: null,
				servers: Array.isArray( p.servers ) ? p.servers : state.servers,
				// Merge abilities when the controller shipped a fresh count —
				// happens whenever the scratchpad has a server_id. Keeps
				// enabledForServer authoritative right after any /step call,
				// so App.jsx's skipAbilities predicate can fire without
				// waiting for a follow-up /state refetch.
				abilities: p.abilities || state.abilities,
				wizardState: { ...state.wizardState, ...( p.wizardState || {} ) },
			};
		}

		case ACTION.SAVE_STEP_ERROR:
			return {
				...state,
				status: 'error',
				error: action.payload.error,
				wizardState: action.payload.rollbackState || state.wizardState,
			};

		case ACTION.COMPLETE_START:
			return { ...state, status: 'saving', error: null };

		case ACTION.COMPLETE_SUCCESS:
			// Deliberately DO NOT reset wizardState here. The Completion
			// screen fires complete() on mount to clear the server-side
			// scratchpad, but it still needs the local wizardState to
			// render the summary rows (server name, method, etc.) and
			// enable the "Go to server dashboard" button (which reads
			// server_id).
			//
			// Client-side reset happens naturally when the user clicks
			// "Set up another server" → refetch() pulls the now-empty
			// scratchpad and the reducer merges defaults in.
			return { ...state, status: 'ready', error: null };

		case ACTION.CLEAR_ERROR:
			return { ...state, status: 'ready', error: null };

		default:
			return state;
	}
};

// ─────────────────────────────────────────────────────────────────────────
// Error normalization (maps apiFetch failures to user-facing messages)
// ─────────────────────────────────────────────────────────────────────────

const normalizeError = ( raw ) => {
	// apiFetch rejects with an object shaped like WP_Error JSON
	// { code, message, data: { status } }
	if ( raw && typeof raw === 'object' ) {
		const status = raw.data?.status || raw.status;
		// TASK-SEC-005 — nonce-expiry user-facing message.
		if ( status === 403 ) {
			return {
				code: 'session_expired',
				message: __(
					'Your session has expired. Please reload the page to continue.',
					'acrossai-mcp-manager'
				),
			};
		}
		if ( status === 401 ) {
			return {
				code: 'not_authorized',
				message: __(
					'You are no longer signed in. Please reload the page.',
					'acrossai-mcp-manager'
				),
			};
		}
		if ( raw.message ) {
			return { code: raw.code || 'error', message: String( raw.message ) };
		}
	}
	return {
		code: 'network_error',
		message: __( 'Something went wrong. Please try again.', 'acrossai-mcp-manager' ),
	};
};

// ─────────────────────────────────────────────────────────────────────────
// Context + Provider
// ─────────────────────────────────────────────────────────────────────────

const WizardStateContext = createContext( null );

export const WizardStateProvider = ( { children } ) => {
	const [ state, dispatch ] = useReducer( reducer, initialState );

	// Prefix for REST routes — read from bootstrap payload.
	const restRoot = useRef( '' );
	if ( ! restRoot.current ) {
		const bootstrap = window.acrossaiMcpQuickSetup || {};
		restRoot.current = bootstrap.restUrl || '/wp-json/acrossai-mcp-manager/v1/quick-setup';
	}

	const refetch = useCallback( async () => {
		dispatch( { type: ACTION.HYDRATE_START } );
		try {
			// Deep-link support (F075 follow-up): if the operator landed on a
			// late step with `?server=N` in the URL (no earlier server-picker
			// step, so the scratchpad's `server_id` is still 0), pass it
			// through to the state endpoint. The backend prefers the
			// scratchpad when both are present.
			const urlParams = new URLSearchParams( window.location.search );
			const serverParam = urlParams.get( 'server' );
			const url = serverParam
				? `${ restRoot.current }/state?server_id=${ encodeURIComponent( serverParam ) }`
				: `${ restRoot.current }/state`;
			const payload = await apiFetch( { url, method: 'GET' } );
			dispatch( { type: ACTION.HYDRATE_SUCCESS, payload } );
			return payload;
		} catch ( err ) {
			dispatch( { type: ACTION.HYDRATE_ERROR, payload: normalizeError( err ) } );
			return null;
		}
	}, [] );

	const saveStep = useCallback( async ( step, data = {} ) => {
		const rollbackState = state.wizardState;
		// Compute the optimistic delta — most step fields are simple mirrors of data.
		const optimistic = { current_step: step, ...data };
		dispatch( { type: ACTION.SAVE_STEP_START, optimistic } );
		try {
			const payload = await apiFetch( {
				url: `${ restRoot.current }/step`,
				method: 'POST',
				data: { step, data },
			} );
			dispatch( { type: ACTION.SAVE_STEP_SUCCESS, payload } );
			return payload;
		} catch ( err ) {
			dispatch( {
				type: ACTION.SAVE_STEP_ERROR,
				payload: { error: normalizeError( err ), rollbackState },
			} );
			return null;
		}
	}, [ state.wizardState ] );

	const complete = useCallback( async () => {
		dispatch( { type: ACTION.COMPLETE_START } );
		try {
			await apiFetch( { url: `${ restRoot.current }/complete`, method: 'POST' } );
			dispatch( { type: ACTION.COMPLETE_SUCCESS } );
			return true;
		} catch ( err ) {
			dispatch( { type: ACTION.HYDRATE_ERROR, payload: normalizeError( err ) } );
			return false;
		}
	}, [] );

	const clearError = useCallback( () => dispatch( { type: ACTION.CLEAR_ERROR } ), [] );

	// Auto-refetch (US3) — pull fresh state whenever the wizard tab
	// becomes user-visible again or the user navigates the browser
	// history. This covers three real-world staleness cases:
	//
	//   1. User switches to another tab, mutates state via a different
	//      wizard admin screen (e.g. disables the server on the list
	//      page), and returns — visibilitychange fires reliably here,
	//      the `focus` event is flaky across Cmd+Tab / click-tab / etc.
	//   2. User clicks browser Back/Forward — popstate fires; without
	//      the refetch, `state.servers` (and derived skips like
	//      skipEnable) can be stale relative to the DB and the wizard
	//      auto-skips a step the user meant to visit again.
	//   3. Same-tab navigation away and back is handled by React
	//      remount (whole store re-hydrates from scratch) — no listener
	//      needed.
	//
	// The plain `focus` listener stays as a belt-and-braces fallback for
	// old browsers where visibilitychange is unreliable.
	useEffect( () => {
		const maybeRefetch = () => {
			if ( state.status === 'ready' ) {
				refetch();
			}
		};
		const onVisibility = () => {
			if ( document.visibilityState === 'visible' ) {
				maybeRefetch();
			}
		};
		window.addEventListener( 'focus', maybeRefetch );
		document.addEventListener( 'visibilitychange', onVisibility );
		window.addEventListener( 'popstate', maybeRefetch );
		return () => {
			window.removeEventListener( 'focus', maybeRefetch );
			document.removeEventListener( 'visibilitychange', onVisibility );
			window.removeEventListener( 'popstate', maybeRefetch );
		};
	}, [ refetch, state.status ] );

	const value = {
		state,
		isLoading: state.status === 'loading' || state.status === 'saving',
		error: state.error,
		refetch,
		saveStep,
		complete,
		clearError,
	};

	return (
		<WizardStateContext.Provider value={ value }>
			{ children }
		</WizardStateContext.Provider>
	);
};

const useWizardState = () => {
	const ctx = useContext( WizardStateContext );
	if ( ! ctx ) {
		throw new Error( 'useWizardState must be used within <WizardStateProvider>' );
	}
	return ctx;
};

export default useWizardState;
