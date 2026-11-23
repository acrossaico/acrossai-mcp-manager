/**
 * Feature 099 — per-step guard contract.
 *
 * The shell owns Back and Continue. Steps lift their requirements into this
 * context rather than rendering their own navigation, so every screen's footer
 * behaves identically and the skip logic stays in one place.
 *
 * @package
 */

import {
	createContext,
	useContext,
	useEffect,
	useRef,
} from '@wordpress/element';

/**
 * Context supplied by App.
 *
 * @type {Object}
 */
export const WizardGuardContext = createContext( {
	setCanAdvance: () => {},
	setBeforeAdvance: () => {},
	setFooterAction: () => {},
	setHideContinue: () => {},
	setBelowFooter: () => {},
	advance: () => {},
} );

/**
 * Enable or disable Continue, with an optional pre-advance hook.
 *
 * @param {boolean}       canAdvance    Whether Continue is enabled.
 * @param {Function|null} beforeAdvance Optional async check; a falsy return cancels navigation.
 */
const useAdvanceGuard = ( canAdvance, beforeAdvance = null ) => {
	const { setCanAdvance, setBeforeAdvance } = useContext( WizardGuardContext );

	useEffect( () => {
		setCanAdvance( !! canAdvance );

		return () => setCanAdvance( true );
	}, [ canAdvance, setCanAdvance ] );

	useEffect( () => {
		// useState treats a function value as an updater, so a callback must be
		// wrapped in another function to be stored rather than invoked.
		setBeforeAdvance( () => beforeAdvance );

		return () => setBeforeAdvance( null );
	}, [ beforeAdvance, setBeforeAdvance ] );
};

/**
 * Register one or more extra footer buttons.
 *
 * Shape: { label, onClick, isLoading, disabled, variant, href, target }.
 *
 * Use `href` + `target` for external links rather than onClick + window.open —
 * popup blockers silently kill the latter, leaving a dead button.
 *
 * @param {Object|Object[]|null} action Footer action(s).
 */
export const useFooterAction = ( action ) => {
	const { setFooterAction } = useContext( WizardGuardContext );

	// Keyed on content, not identity.
	//
	// useEffect compares dependencies by identity, and the natural way to call
	// this hook is with an object literal — which is a new identity on every
	// render. Keyed on `action` directly, the effect re-ran every render, set
	// shell state, re-rendered, and started again. Measured on screen 6 before
	// this guard: ~1,076 renders per second, and nothing visibly wrong on the
	// page, so it survived several rounds of manual testing unnoticed.
	//
	// Callers should not have to memoise to avoid pinning a CPU core. Comparing
	// the fields the shell actually renders makes the obvious usage correct.
	const actions = action ? [].concat( action ).filter( Boolean ) : [];
	const signature = JSON.stringify(
		actions.map( ( entry ) => [
			entry.label,
			entry.href || '',
			entry.target || '',
			entry.variant || '',
			!! entry.isLoading,
			!! entry.disabled,
		] ),
	);

	// onClick is deliberately absent from the signature: a fresh arrow function
	// every render would defeat the whole point. This ref keeps the newest one
	// available, and the effect re-captures whenever a rendered field changes —
	// isLoading and disabled included, which is when a handler's closure would
	// actually have moved on.
	const latest = useRef( action );
	latest.current = action;

	useEffect( () => {
		setFooterAction( latest.current || null );

		return () => setFooterAction( null );
	}, [ signature, setFooterAction ] );
};

/**
 * Hide Continue entirely.
 *
 * For gate screens that cannot be completed from inside the wizard — a
 * permanently dead button invites confused clicking.
 *
 * @param {boolean} hide Whether to hide Continue.
 */
export const useHideContinue = ( hide ) => {
	const { setHideContinue } = useContext( WizardGuardContext );

	useEffect( () => {
		setHideContinue( !! hide );

		return () => setHideContinue( false );
	}, [ hide, setHideContinue ] );
};

/**
 * Render supplemental content below the footer.
 *
 * Keeps long explanatory content from pushing the primary action below the fold.
 *
 * @param {Element|null} node Content to render after the footer.
 */
export const useBelowFooter = ( node ) => {
	const { setBelowFooter } = useContext( WizardGuardContext );

	useEffect( () => {
		setBelowFooter( node || null );

		return () => setBelowFooter( null );
	}, [ node, setBelowFooter ] );
};

/**
 * A stable advance() bound to App's memoized skip flags.
 *
 * Steps must use this rather than recomputing skips locally, which would drift
 * from the shell's own view of the flow.
 *
 * @return {Function} advance()
 */
export const useWizardAdvance = () => {
	const { advance } = useContext( WizardGuardContext );

	return advance;
};

export default useAdvanceGuard;
