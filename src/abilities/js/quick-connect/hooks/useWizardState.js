/**
 * Feature 099 — wizard state.
 *
 * Fetch-only. The sibling wizard persists progress in a per-user scratchpad and
 * exposes /step and /complete; none of that exists here because screens 1-4 are
 * read-only and the transport choice lives in the URL (spec FR-015). There is no
 * saveStep and no complete — only a hydrate and a refetch.
 *
 * @package
 */

import {
	createContext,
	useContext,
	useCallback,
	useEffect,
	useMemo,
	useReducer,
	useRef,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Initial client state.
 *
 * @type {Object}
 */
export const initialState = {
	status: 'idle', // idle | loading | ready | error
	error: null,
	abilities: { total: 0, tabGroups: [] },
	plugins: {
		mcpManager: 'missing',
		mcpAdapter: 'missing',
		mcpManagerWizardUrl: '',
	},
	canInstall: false,
};

/**
 * State reducer.
 *
 * Named export so transitions are unit-testable without rendering
 * (PATTERN-NAMED-EXPORT-JEST).
 *
 * @param {Object} state  Current state.
 * @param {Object} action Dispatched action.
 * @return {Object} Next state.
 */
export const reducer = ( state, action ) => {
	switch ( action.type ) {
		case 'HYDRATE_START':
			return { ...state, status: 'loading', error: null };

		case 'HYDRATE_SUCCESS':
			return {
				...state,
				status: 'ready',
				error: null,
				abilities: action.payload.abilities || initialState.abilities,
				plugins: action.payload.plugins || initialState.plugins,
				canInstall: !! action.payload.canInstall,
			};

		case 'HYDRATE_ERROR':
			return { ...state, status: 'error', error: action.error };

		case 'CLEAR_ERROR':
			return { ...state, error: null };

		default:
			return state;
	}
};

/**
 * Convert a thrown API error into something worth showing a human.
 *
 * Named export for testability.
 *
 * @param {Object} raw Error thrown by apiFetch.
 * @return {{message: string}} Display-ready error.
 */
export const normalizeError = ( raw ) => {
	const status = raw?.data?.status;

	if ( status === 403 ) {
		return {
			message: __(
				'Your session has expired. Reload the page to continue.',
				'acrossai-abilities-manager',
			),
		};
	}

	if ( status === 401 ) {
		return {
			message: __(
				'You are no longer signed in.',
				'acrossai-abilities-manager',
			),
		};
	}

	if ( raw?.message ) {
		return { message: String( raw.message ) };
	}

	return {
		message: __(
			'Could not reach the site. Check your connection and try again.',
			'acrossai-abilities-manager',
		),
	};
};

const WizardStateContext = createContext( null );

/**
 * Provides wizard state to the tree.
 *
 * @param {Object}  props          Component props.
 * @param {Element} props.children Child tree.
 * @return {Element} Provider element.
 */
export const WizardStateProvider = ( { children } ) => {
	const [ state, dispatch ] = useReducer( reducer, initialState );

	const refetch = useCallback( async () => {
		const bootstrap = window.acrossaiQuickConnect || {};
		const restRoot =
			bootstrap.restUrl || '/wp-json/acrossai/v1/quick-connect';

		dispatch( { type: 'HYDRATE_START' } );

		try {
			const data = await apiFetch( { url: `${ restRoot }/state` } );
			dispatch( { type: 'HYDRATE_SUCCESS', payload: data || {} } );
			return data;
		} catch ( error ) {
			dispatch( { type: 'HYDRATE_ERROR', error: normalizeError( error ) } );
			return null;
		}
	}, [] );

	const clearError = useCallback( () => dispatch( { type: 'CLEAR_ERROR' } ), [] );

	// Refetch when the operator returns to the tab. An install performed in
	// another tab is picked up without polling — this is why the adapter path
	// needs no timer.
	useEffect( () => {
		const onFocus = () => refetch();
		const onVisibility = () => {
			if ( document.visibilityState === 'visible' ) {
				refetch();
			}
		};

		window.addEventListener( 'focus', onFocus );
		document.addEventListener( 'visibilitychange', onVisibility );

		return () => {
			window.removeEventListener( 'focus', onFocus );
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ refetch ] );

	const value = useMemo(
		() => ( {
			state,
			isLoading: state.status === 'loading',
			error: state.error,
			refetch,
			clearError,
		} ),
		[ state, refetch, clearError ],
	);

	return (
		<WizardStateContext.Provider value={ value }>
			{ children }
		</WizardStateContext.Provider>
	);
};

/**
 * Consume wizard state.
 *
 * @return {Object} { state, isLoading, error, refetch, clearError }.
 */
const useWizardState = () => {
	const context = useContext( WizardStateContext );

	if ( ! context ) {
		throw new Error(
			'useWizardState must be used inside <WizardStateProvider>.',
		);
	}

	return context;
};

/**
 * Track whether a first successful hydrate has happened.
 *
 * After the first hydrate, later loading states must not unmount the current
 * step — otherwise every tab-return would lose local state and, on gate screens
 * that refetch on mount, loop into a blank screen.
 *
 * @param {string} status Current status.
 * @return {boolean} True once a hydrate has succeeded.
 */
export const useHasHydratedOnce = ( status ) => {
	const ref = useRef( false );

	if ( status === 'ready' ) {
		ref.current = true;
	}

	return ref.current;
};

export default useWizardState;
