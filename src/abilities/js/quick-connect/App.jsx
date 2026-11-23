/**
 * Feature 099 — wizard root.
 *
 * Owns the step registry, the skip predicates, the visibility table that keeps
 * the progress indicator honest, and the guard context the steps lift into.
 *
 * Navigation is state-driven: a step mutates state, refetches, a skip predicate
 * flips, and the auto-skip effect moves the operator. Steps must never call
 * `advance()` imperatively after an action — doing so races the auto-skip effect
 * and double-jumps, which is a bug the sibling wizard paid for repeatedly.
 *
 * @package
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import StepLayout from './StepLayout.jsx';
import Notice from './components/Notice.jsx';
import Step1AbilitiesOverview from './steps/Step1AbilitiesOverview.jsx';
import Step2EditAbility from './steps/Step2EditAbility.jsx';
import Step3BulkActions from './steps/Step3BulkActions.jsx';
import Step4Integrations from './steps/Step4Integrations.jsx';
import Step5ConnectTransport from './steps/Step5ConnectTransport.jsx';
import Step6AdapterInstall from './steps/Step6AdapterInstall.jsx';
import Step7AdapterAbilities from './steps/Step7AdapterAbilities.jsx';
import Completion from './steps/Completion.jsx';
import useWizardRouter, { STEP_ORDER } from './hooks/useWizardRouter.js';
import useWizardState, { useHasHydratedOnce } from './hooks/useWizardState.js';
import { WizardGuardContext } from './hooks/useAdvanceGuard.js';

/**
 * Step id to component factory.
 *
 * Every entry currently resolves to the placeholder; Phases 3-7 swap them for
 * real screens one at a time.
 *
 * @type {Object<string, Function>}
 */
const stepRegistry = {
	1: () => <Step1AbilitiesOverview />,
	2: () => <Step2EditAbility />,
	3: () => <Step3BulkActions />,
	4: () => <Step4Integrations />,
	5: () => <Step5ConnectTransport />,
	6: () => <Step6AdapterInstall />,
	7: () => <Step7AdapterAbilities />,
	done: () => <Completion />,
};

/**
 * Compute skip predicates from wizard state.
 *
 * Named export so the flow can be unit-tested without rendering
 * (PATTERN-NAMED-EXPORT-JEST).
 *
 * @param {string} method  Selected transport.
 * @param {Object} plugins Transport states from /state.
 * @return {{skipAdapterInstall: boolean, skipAdapterAbilities: boolean}} Skip flags.
 */
export const computeSkips = (method, plugins = {}) => ({
	// Installation instructions are pointless when the adapter is already
	// present — the operator goes straight to the enablement walkthrough
	// (spec FR-011a).
	skipAdapterInstall:
		method !== 'mcp-adapter' || plugins.mcpAdapter === 'active',
	skipAdapterAbilities: method !== 'mcp-adapter',
});

/**
 * Table of every step and whether it is hidden.
 *
 * Single source of truth: totalSteps, displayIndex and shouldSkip all read from
 * here, so adding a step means adding one row.
 *
 * @param {Object} skips Skip flags.
 * @return {Array<{id: string, skip: boolean}>} Visibility rows.
 */
export const buildVisibilityTable = (skips) => [
	{ id: '1', skip: false },
	{ id: '2', skip: false },
	{ id: '3', skip: false },
	{ id: '4', skip: false },
	{ id: '5', skip: false },
	{ id: '6', skip: !!skips.skipAdapterInstall },
	{ id: '7', skip: !!skips.skipAdapterAbilities },
];

/**
 * Count the steps the operator will actually see.
 *
 * @param {Array} table Visibility rows.
 * @return {number} Visible step count.
 */
export const computeTotalSteps = (table) =>
	table.filter((row) => !row.skip).length;

/**
 * Position of the current step among the visible ones.
 *
 * @param {string} step       Current step id.
 * @param {Array}  table      Visibility rows.
 * @param {number} totalSteps Visible step count.
 * @return {number} 1-based display index.
 */
export const computeDisplayIndex = (step, table, totalSteps) => {
	if (step === 'done') {
		return totalSteps;
	}

	let index = 0;

	for (const row of table) {
		if (row.skip) {
			continue;
		}

		index += 1;

		if (row.id === String(step)) {
			return index;
		}
	}

	return index;
};

/**
 * Wizard root component.
 *
 * @return {Element} The wizard.
 */
const App = () => {
	const router = useWizardRouter();
	const { state, isLoading, error, refetch, clearError } = useWizardState();

	const [canAdvance, setCanAdvance] = useState(true);
	const [beforeAdvance, setBeforeAdvance] = useState(null);
	const [footerAction, setFooterAction] = useState(null);
	const [hideContinue, setHideContinue] = useState(false);
	const [belowFooter, setBelowFooter] = useState(null);

	// Hydrate once on mount.
	useEffect(() => {
		if (state.status === 'idle') {
			refetch();
		}
	}, []); // eslint-disable-line react-hooks/exhaustive-deps

	const skips = useMemo(
		() => computeSkips(router.method, state.plugins),
		[router.method, state.plugins]
	);

	const visibilityTable = useMemo(() => buildVisibilityTable(skips), [skips]);
	const totalSteps = useMemo(
		() => computeTotalSteps(visibilityTable),
		[visibilityTable]
	);
	const displayIndex = useMemo(
		() => computeDisplayIndex(router.step, visibilityTable, totalSteps),
		[router.step, visibilityTable, totalSteps]
	);

	// Auto-skip: if the operator lands on a step that does not apply — via a deep
	// link, browser Back, or a state change while parked on a gate screen — walk
	// forward to the next applicable one rather than showing a dead end (FR-013).
	useEffect(() => {
		if (state.status !== 'ready') {
			return;
		}

		if (router.step === '6' && skips.skipAdapterInstall) {
			router.advance({ skips });
			return;
		}

		if (router.step === '7' && skips.skipAdapterAbilities) {
			router.advance({ skips });
		}
	}, [router.step, state.status, skips, router]);

	// An unrecognised step id (a stale bookmark, say) is corrected rather than
	// silently rendering step 1 while the URL claims otherwise.
	useEffect(() => {
		if (!STEP_ORDER.includes(router.step)) {
			router.goTo('1');
		}
	}, [router.step, router]);

	const advanceFromContext = useCallback(() => {
		router.advance({ skips });
	}, [router, skips]);

	const guardContext = useMemo(
		() => ({
			setCanAdvance,
			setBeforeAdvance,
			setFooterAction,
			setHideContinue,
			setBelowFooter,
			advance: advanceFromContext,
		}),
		[advanceFromContext]
	);

	// One source of truth for "this screen ends the wizard". StepLayout used to
	// infer it from the step id alone, which mislabelled Continue as "Finish" on
	// step 5 whenever the adapter path still had screens to show.
	const isTerminal =
		(router.step === '5' && skips.skipAdapterAbilities) ||
		router.step === '7';

	const handleContinue = useCallback(async () => {
		if (beforeAdvance) {
			const ok = await beforeAdvance();

			if (!ok) {
				return;
			}
		}

		// Terminal screens finish rather than advancing into a step that does not
		// apply to this path.
		if (isTerminal) {
			router.goTo('done');
			return;
		}

		router.advance({ skips });
	}, [router, skips, beforeAdvance, isTerminal]);

	const hasHydratedOnce = useHasHydratedOnce(state.status);
	const bootstrap = window.acrossaiQuickConnect || {};

	// Cold start only. Once hydrated, later loading states show the overlay over
	// the current step instead of unmounting it — otherwise returning to the tab
	// would lose local state and, on gate screens that refetch on mount, loop.
	if (
		state.status === 'idle' ||
		(state.status === 'loading' && !hasHydratedOnce)
	) {
		return (
			<div className="qs__initial-loading">
				{bootstrap.iconUrl && (
					<img
						className="qs__initial-loading-icon"
						src={bootstrap.iconUrl}
						alt=""
						aria-hidden="true"
					/>
				)}
				<span className="qs__sr-only">
					{__(
						'Loading the setup wizard…',
						'acrossai-abilities-manager'
					)}
				</span>
			</div>
		);
	}

	const renderStep = () => (stepRegistry[router.step] || stepRegistry[1])();

	return (
		<WizardGuardContext.Provider value={guardContext}>
			<StepLayout
				step={router.step}
				displayIndex={displayIndex}
				totalSteps={totalSteps}
				isLoading={isLoading}
				canAdvance={canAdvance}
				footerAction={footerAction}
				hideContinue={hideContinue}
				belowFooter={belowFooter}
				isTerminal={isTerminal}
				onBack={() => router.back({ skips })}
				onAdvance={handleContinue}
				onExit={router.exit}
			>
				{error && (
					<Notice status="error">
						{error.message}{' '}
						<button
							type="button"
							className="qs-btn qs-btn--link"
							onClick={clearError}
						>
							{__('Dismiss', 'acrossai-abilities-manager')}
						</button>
					</Notice>
				)}
				{renderStep()}
			</StepLayout>
		</WizardGuardContext.Provider>
	);
};

export default App;
