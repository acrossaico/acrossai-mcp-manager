/**
 * F069 — Wizard root component.
 *
 * Top-level orchestrator for the dynamic 5-to-7-step Quick Connect via AcrossAI wizard:
 *   - On mount, hydrates from `GET /quick-connect/state`.
 *   - Reads `step` + `method` from URL via useWizardRouter().
 *   - Computes skip flags from state (server_id + create_intent; abilities
 *     manager plugin state; server enabled state) and passes them to
 *     router.advance/back so 2 / 4 / 6 are transparently walked past.
 *   - Auto-skip effect handles a direct navigation (browser Back /
 *     forward, refresh, deep link) that lands on a step whose skip
 *     predicate is true — silently forwards to the next legitimate step.
 *   - Deep-link precondition guard: no server_id → force step 1;
 *     landing on step 3+ with no server_id would break every downstream
 *     query.
 *   - Renders <StepLayout>{stepComponent}</StepLayout>.
 *   - Provides WizardGuardContext so per-step useAdvanceGuard() can lift
 *     both `canAdvance` state and an optional `beforeAdvance` submit
 *     callback (Step 2 uses it to POST /step 2 on Continue click).
 *
 * @package AcrossAI_MCP_Manager
 */

import { useEffect, useMemo, useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from './components/Notice.jsx';
import StepLayout from './StepLayout.jsx';
import useWizardRouter from './hooks/useWizardRouter.js';
import useWizardState from './hooks/useWizardState.js';
import { WizardGuardContext } from './hooks/useAdvanceGuard.js';

import Step1_ServerPick from './steps/Step1_ServerPick.jsx';
import Step2_ServerCreate from './steps/Step2_ServerCreate.jsx';
import Step3_AccessControl from './steps/Step3_AccessControl.jsx';
import Step4_AbilitiesManager from './steps/Step4_AbilitiesManager.jsx';
import Step5_Abilities from './steps/Step5_Abilities.jsx';
import Step6_EnableServer from './steps/Step6_EnableServer.jsx';
import Step7_MethodGrid from './steps/Step7_MethodGrid.jsx';
import Step8_ProPromo from './steps/Step8_ProPromo.jsx';
import Step9_ProSetup from './steps/Step9_ProSetup.jsx';
import Step10_ConnectorsDetail from './steps/Step10_ConnectorsDetail.jsx';
import Step11_ClientDetail from './steps/Step11_ClientDetail.jsx';
import Step12_NpmDetail from './steps/Step12_NpmDetail.jsx';
import Step13_WpCliDetail from './steps/Step13_WpCliDetail.jsx';
import Completion from './steps/Completion.jsx';

// Steps 3+ require a chosen server. Landing there without one is a broken
// deep-link — bounce the user back to step 1.
const furthestLegitimateStep = ( wizardState ) => {
	if ( ! wizardState.server_id && ! wizardState.create_intent ) {
		return '1';
	}
	return null;
};

const stepRegistry = {
	'1': () => <Step1_ServerPick />,
	'2': () => <Step2_ServerCreate />,
	'3': () => <Step3_AccessControl />,
	'4': () => <Step4_AbilitiesManager />,
	'5': () => <Step5_Abilities />,
	'6': () => <Step6_EnableServer />,
	'7': () => <Step7_MethodGrid />,
	'8': () => <Step8_ProPromo />,
	'9': () => <Step9_ProSetup />,
	'10': () => <Step10_ConnectorsDetail />,
	'11': () => <Step11_ClientDetail />,
	'12': () => <Step12_NpmDetail />,
	'13': () => <Step13_WpCliDetail />,
	'done': () => <Completion />,
};

const App = () => {
	const router = useWizardRouter();
	const { state, isLoading, error, refetch, clearError } = useWizardState();
	const [ canAdvance, setCanAdvance ] = useState( true );
	const [ beforeAdvance, setBeforeAdvance ] = useState( null );
	const [ footerAction, setFooterAction ] = useState( null );
	const [ hideContinue, setHideContinue ] = useState( false );
	const [ belowFooter, setBelowFooter ] = useState( null );

	// Hydrate on mount.
	useEffect( () => {
		if ( state.status === 'idle' ) {
			refetch();
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Mirror wizardState.server_id into the URL as `?server=<id>` for
	// shareable deep-links. Uses replaceState under the hood so it doesn't
	// pollute browser history. Cosmetic only — the scratchpad remains the
	// authoritative source of server_id for step 2+.
	useEffect( () => {
		if ( state.status !== 'ready' ) {
			return;
		}
		router.setServer( state.wizardState.server_id );
	}, [ state.status, state.wizardState.server_id, router ] );

	// Currently-selected server DTO — pulled from the DB-backed servers list
	// so downstream code sees authoritative `enabled` state (the scratchpad's
	// `enabled` flag is set by the wizard's own writes and doesn't reflect a
	// server that was already enabled before the wizard started).
	const selectedServer = useMemo(
		() =>
			state.servers.find(
				( s ) => s.id === state.wizardState.server_id
			) || null,
		[ state.servers, state.wizardState.server_id ]
	);

	const method = state.wizardState.method;
	const proState = state.plugins.acrossaiPro; // 'missing' | 'inactive' | 'active'
	// F074 — a licence (paid or trial) connected via Freemius. Plugin-active
	// alone is not enough to use Connectors; see skipProSetup below.
	const proLicensed = !! state.plugins.acrossaiProLicensed;

	// Skip predicates — recomputed whenever the underlying state changes so
	// advance/back always walk past the right steps.
	const skips = useMemo( () => ( {
		// Step 2 (create form) is skipped when the user picked an existing
		// server in Step 1 (server_id set AND create_intent NOT set).
		skipCreate:
			state.wizardState.server_id !== null &&
			! state.wizardState.create_intent,
		// Step 4 (abilities-manager gate) is skipped when the plugin is
		// already active.
		skipAbilitiesGate: state.plugins.abilitiesManager === 'active',
		// Step 5 (abilities picker) is skipped when every ability is already
		// enabled for this server — nothing left for the user to do here.
		// Guard on total > 0 so we don't skip when the count hasn't loaded.
		skipAbilities:
			state.abilities.total > 0 &&
			state.abilities.enabledForServer !== null &&
			state.abilities.enabledForServer >= state.abilities.total,
		// Step 6 (enable endpoint) is skipped when the server is already on
		// in the DB. Reads from state.servers (authoritative) rather than
		// wizardState.enabled — the scratchpad flag only reflects writes made
		// by the wizard itself, so a pre-existing enabled server wouldn't
		// be recognized without this DB-backed check.
		skipEnable: !! ( selectedServer && selectedServer.enabled ),
		// Steps 8-13 are one-of-many terminal method-specific screens. Only
		// the branch matching the user's picked method + pro state renders.
		// Step 8 = Pro pitch (missing); Step 9 = Pro install/activate/licence;
		// Step 10 = Connectors detail (active + licensed); 11 = Client; 12 = npm;
		// 13 = WP-CLI.
		// Step 8 (the pitch + "Start free trial" CTA) stays available until Pro
		// is fully usable — installed AND licensed. Showing it only while the
		// plugin was 'missing' meant anyone who had installed acrossai-pro but
		// never connected a licence had no route to the trial CTA at all.
		skipProPromo: ! (
			method === 'connectors' &&
			! ( proState === 'active' && proLicensed )
		),
		// Step 9 is the single "get Pro working" screen — install,
		// activate, licence. It stays up until acrossai-pro is BOTH active
		// and licensed, because the plugin registers zero connector profiles
		// without a licence; treating merely-active as done would drop the
		// user on a Connectors screen that cannot work. Step 10
		// correspondingly requires active AND licensed.
		skipProSetup: ! (
			method === 'connectors' &&
			! ( proState === 'active' && proLicensed )
		),
		skipConnectorsDetail: ! (
			method === 'connectors' &&
			proState === 'active' &&
			proLicensed
		),
		skipClient: method !== 'client',
		skipNpm: method !== 'npm',
		skipWpcli: method !== 'wpcli',
	} ), [
		state.wizardState.server_id,
		state.wizardState.create_intent,
		state.plugins.abilitiesManager,
		state.abilities.total,
		state.abilities.enabledForServer,
		selectedServer,
		method,
		proState,
		proLicensed,
	] );

	// Auto-skip effect — silently forwards the user past any skipped step
	// they land on directly (deep link, browser Back, or state change while
	// parked on a gate step waiting for external action to complete).
	//
	// Every branch uses router.advance so chained skips resolve in one call
	// (e.g. from step 4 → 5 → 6 → 7 when the middle steps are all skipped).
	useEffect( () => {
		if ( state.status !== 'ready' ) {
			return;
		}
		if ( router.step === '2' && skips.skipCreate ) {
			router.advance( { skips } );
			return;
		}
		if ( router.step === '4' && skips.skipAbilitiesGate ) {
			router.advance( { skips } );
			return;
		}
		if ( router.step === '5' && skips.skipAbilities ) {
			router.advance( { skips } );
			return;
		}
		if ( router.step === '6' && skips.skipEnable ) {
			router.advance( { skips } );
			return;
		}
		// Steps 8-13 are method-specific one-of-many detail/gate screens.
		// When a user lands on one whose skip predicate is true (e.g. they
		// picked Connectors + Pro is active → step 8 (pitch) is skipped;
		// they landed on step 11 (Client) but their method is npm → skip)
		// walk forward until we hit the branch matching the current state.
		if ( router.step === '8' && skips.skipProPromo ) {
			router.advance( { skips } );
			return;
		}
		// Step 9 parks the operator until Pro is active AND licensed.
		if ( router.step === '9' && skips.skipProSetup ) {
			router.advance( { skips } );
			return;
		}
		if ( router.step === '10' && skips.skipConnectorsDetail ) {
			router.advance( { skips } );
			return;
		}
		if ( router.step === '11' && skips.skipClient ) {
			router.advance( { skips } );
			return;
		}
		if ( router.step === '12' && skips.skipNpm ) {
			router.advance( { skips } );
			return;
		}
		if ( router.step === '13' && skips.skipWpcli ) {
			router.advance( { skips } );
		}
	}, [ router.step, state.status, skips, router, proState ] );

	// Deep-link precondition guard — anything past Step 1 requires a chosen
	// server (or create_intent en route to Step 2).
	useEffect( () => {
		if ( state.status !== 'ready' ) {
			return;
		}
		// Unknown step id — e.g. a bookmark or browser-history entry pointing
		// at a retired step id. renderCurrentStep() would silently
		// fall back to Step 1's component while the URL still claimed the dead
		// step, so send them to Step 1 properly and fix the URL with it.
		if ( ! stepRegistry[ router.step ] ) {
			router.goTo( '1' );
			return;
		}
		if ( router.step === '1' || router.step === 'done' ) {
			return;
		}
		const forced = furthestLegitimateStep( state.wizardState );
		if ( forced && forced !== router.step ) {
			router.goTo( forced );
		}
	}, [ router.step, state.status, state.wizardState, router ] );

	// Table of every step + the flag that hides it. Keeps totalSteps and
	// displayIndex in lock-step — one source of truth so adding future
	// steps only means adding a row here.
	const stepVisibilityTable = useMemo( () => [
		{ id: 1, skip: false },
		{ id: 2, skip: skips.skipCreate },
		{ id: 3, skip: false },
		{ id: 4, skip: skips.skipAbilitiesGate },
		{ id: 5, skip: skips.skipAbilities },
		{ id: 6, skip: skips.skipEnable },
		{ id: 7, skip: false },
		{ id: 8, skip: skips.skipProPromo },
		{ id: 9, skip: skips.skipProSetup },
		{ id: 10, skip: skips.skipConnectorsDetail },
		{ id: 11, skip: skips.skipClient },
		{ id: 12, skip: skips.skipNpm },
		{ id: 13, skip: skips.skipWpcli },
	], [ skips ] );

	// Dynamic total step count — sum of non-skipped rows.
	const totalSteps = useMemo(
		() => stepVisibilityTable.filter( ( row ) => ! row.skip ).length,
		[ stepVisibilityTable ]
	);

	// Dynamic display index — count non-skipped rows up to and including
	// the current one so users see "Step 3 of 5" rather than raw router IDs.
	const displayIndex = useMemo( () => {
		if ( router.step === 'done' ) {
			return totalSteps;
		}
		// Compare as strings so numeric row ids match the string step id.
		const target = String( router.step );
		let idx = 0;
		for ( const row of stepVisibilityTable ) {
			if ( row.skip ) continue;
			idx += 1;
			if ( String( row.id ) === target ) return idx;
		}
		return idx;
	}, [ router.step, totalSteps, stepVisibilityTable ] );

	// ⚠️  All hooks MUST be declared unconditionally before ANY early return —
	// Rules of Hooks. Order + count must be identical across every render.
	// Bound advance for footer-action callbacks — same skip flags Continue uses.
	const advanceFromContext = useCallback( () => {
		router.advance( { skips } );
	}, [ router, skips ] );

	const guardContext = useMemo(
		() => ( {
			setCanAdvance,
			setBeforeAdvance,
			setFooterAction,
			setHideContinue,
			setBelowFooter,
			advance: advanceFromContext,
		} ),
		[
			setCanAdvance,
			setBeforeAdvance,
			setFooterAction,
			setHideContinue,
			setBelowFooter,
			advanceFromContext,
		]
	);

	// Terminal detail/gate steps — after these, the wizard finishes. Steps
	// 10-13 are the four one-of-many method screens (only one shows per
	// run) and step 9 is the Pro-activate gate, but a user can never
	// "Finish" from step 8/9 because Continue is disabled there — those
	// gate steps require external plugin action to unblock. So the true
	// Finish-fires-on-Continue steps are 10, 11, 12, 13.
	const isTerminalStep = [ '10', '11', '12', '13' ].includes( router.step );

	const handleContinue = useCallback( async () => {
		// Give the current step a chance to submit / validate first.
		if ( beforeAdvance ) {
			const ok = await beforeAdvance();
			if ( ! ok ) return;
		}
		if ( isTerminalStep ) {
			// Finish button — /complete + navigate to done.
			router.goTo( 'done' );
			return;
		}
		router.advance( { skips } );
	}, [ router, skips, beforeAdvance, isTerminalStep ] );

	const renderCurrentStep = () => {
		const factory = stepRegistry[ router.step ] || stepRegistry[ '1' ];
		return factory();
	};

	// Track whether we've EVER completed a hydrate. Once true, subsequent
	// 'loading' states (from visibilitychange / focus / popstate refetches)
	// no longer trigger the full-screen initial-loading gate — the wizard
	// stays mounted and StepLayout's `busy` overlay shows the loading UI
	// on top of the current step. Without this ref, every tab-return
	// would unmount whatever step the user is on, flash the icon, remount
	// the step (losing local form state, scroll position, and — for gate
	// steps that refetch on mount — infinite-looping into a blank screen).
	const hasHydratedOnceRef = useRef( false );
	if ( state.status === 'ready' ) {
		hasHydratedOnceRef.current = true;
	}

	// Loading screen — only on TRUE cold start (idle) or the very first
	// hydrate (loading + never-been-ready). All hooks above have already
	// run so the early-return here doesn't violate the Rules of Hooks.
	if (
		state.status === 'idle' ||
		( state.status === 'loading' && ! hasHydratedOnceRef.current )
	) {
		const bootstrap = window.acrossaiMcpQuickConnect || {};
		return (
			<div className="qs__initial-loading">
				{ bootstrap.iconUrl ? (
					<img
						className="qs__initial-loading-icon"
						src={ bootstrap.iconUrl }
						alt=""
						aria-hidden="true"
					/>
				) : null }
				<span className="qs__sr-only">
					{ __( 'Loading setup wizard…', 'acrossai-mcp-manager' ) }
				</span>
			</div>
		);
	}

	return (
		<WizardGuardContext.Provider value={ guardContext }>
			<StepLayout
				step={ router.step }
				displayIndex={ displayIndex }
				totalSteps={ totalSteps }
				isLoading={ isLoading }
				canAdvance={ canAdvance }
				footerAction={ footerAction }
				hideContinue={ hideContinue }
				belowFooter={ belowFooter }
				onBack={ () => router.back( { skips } ) }
				onAdvance={ handleContinue }
				onExit={ router.exit }
			>
				{ error && (
					<Notice status="error">
						{ error.message }
						{ ' ' }
						<button
							type="button"
							className="qs-btn qs-btn--link"
							onClick={ clearError }
						>
							{ __( 'Dismiss', 'acrossai-mcp-manager' ) }
						</button>
					</Notice>
				) }
				{ renderCurrentStep() }
			</StepLayout>
		</WizardGuardContext.Provider>
	);
};

export default App;
