/**
 * F069 — Per-step advance-guard context.
 *
 * Each step component may set its `canAdvance` boolean via the shared
 * WizardGuardContext. App.jsx reads it and drives the Continue button's
 * disabled + aria-disabled state. Contract from contracts/react-router.md
 * § Step guard contract.
 *
 * Steps that need to run work BEFORE the router walks forward (e.g. Step 2
 * submitting the create-server form on Continue) can optionally pass a
 * `beforeAdvance` async function. App.jsx awaits it; a falsy return value
 * cancels the transition. This keeps step-specific submit logic in the step
 * component while the Continue button lives in <StepLayout>.
 *
 * @package AcrossAI_MCP_Manager
 */

import { createContext, useContext, useEffect } from '@wordpress/element';

export const WizardGuardContext = createContext( {
	setCanAdvance: () => {},
	setBeforeAdvance: () => {},
	setFooterAction: () => {},
	setHideContinue: () => {},
	advance: () => {},
} );

/**
 * Called from a step component:
 *   useAdvanceGuard( wizardState.server_id !== null );
 *   useAdvanceGuard( form.isValid, async () => { ...submit; return ok; } );
 *
 * @param {boolean} canAdvance     Whether Continue should be enabled.
 * @param {Function|null} beforeAdvance Optional async fn to run on Continue click.
 *   Return truthy to proceed with navigation, falsy to cancel.
 */
const useAdvanceGuard = ( canAdvance, beforeAdvance = null ) => {
	const { setCanAdvance, setBeforeAdvance } = useContext( WizardGuardContext );
	useEffect( () => {
		setCanAdvance( !! canAdvance );
		// useState treats a function value as an updater — wrap the callback
		// in a thunk so React stores the function itself, not its return.
		setBeforeAdvance( () => beforeAdvance );
		return () => {
			// On unmount, reset both so the next step doesn't inherit stale
			// disabled state or a stale submit handler.
			setCanAdvance( true );
			setBeforeAdvance( () => null );
		};
	}, [ canAdvance, beforeAdvance, setCanAdvance, setBeforeAdvance ] );
};

/**
 * Register an optional third footer button (e.g. Step 5's "Enable all and
 * continue"). Pass null / undefined `action` to clear.
 *
 *   useFooterAction( allEnabled ? null : {
 *     label: 'Enable all and continue',
 *     onClick: async () => { await enableAll(); router.advance( ... ); },
 *     isLoading,
 *     disabled,
 *   } );
 *
 * StepLayout renders the button between Back and Continue on any step that
 * registers one, styled as PRIMARY; the built-in Continue collapses to
 * SECONDARY on that render so the recommended action visually leads.
 *
 * @param {object|null} action { label, onClick, isLoading, disabled } or null.
 */
export const useFooterAction = ( action ) => {
	const { setFooterAction } = useContext( WizardGuardContext );
	useEffect( () => {
		setFooterAction( action || null );
		return () => setFooterAction( null );
	}, [ action, setFooterAction ] );
};

/**
 * Return a stable `advance()` function that walks the wizard forward using
 * the SAME skip flags App.jsx computes for Continue-button navigation.
 * Steps whose footer action needs to trigger navigation (Step 3's
 * "Save and Continue", Step 5's "Enable all and continue") should use
 * this instead of reconstructing skips locally — otherwise the two skip
 * computations can drift.
 */
export const useWizardAdvance = () => {
	const { advance } = useContext( WizardGuardContext );
	return advance;
};

/**
 * Hide the wizard's built-in Continue button on this step. Use when the
 * only legitimate forward path is a footerAction (Step 6's "Enable &
 * Continue" — a disabled Continue button would just confuse users into
 * clicking it repeatedly). Back and any registered footerAction still
 * render normally.
 *
 * Pass true to hide, false / omitted to restore. Auto-restores on unmount.
 */
export const useHideContinue = ( hide = true ) => {
	const { setHideContinue } = useContext( WizardGuardContext );
	useEffect( () => {
		setHideContinue( !! hide );
		return () => setHideContinue( false );
	}, [ hide, setHideContinue ] );
};

export default useAdvanceGuard;
