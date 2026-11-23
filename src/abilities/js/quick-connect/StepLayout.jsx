/**
 * Feature 099 — wizard shell.
 *
 * Owns the progress bar, header, footer, busy overlay, and accessibility
 * plumbing. Steps render content only; they never draw their own navigation, so
 * every screen behaves identically.
 *
 * Ported from the sibling wizard. Visual parity with it is spec SC-005, so the
 * markup and class vocabulary are deliberately unchanged.
 *
 * @package
 */

import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Human-readable step titles, announced to assistive technology.
 *
 * @type {Object<string, string>}
 */
const STEP_TITLES = {
	1: __('Your abilities', 'acrossai-abilities-manager'),
	2: __('Editing an ability', 'acrossai-abilities-manager'),
	3: __('Bulk actions', 'acrossai-abilities-manager'),
	4: __('Integrations', 'acrossai-abilities-manager'),
	5: __('Connect a transport', 'acrossai-abilities-manager'),
	6: __('Install MCP Adapter', 'acrossai-abilities-manager'),
	7: __('Enable abilities', 'acrossai-abilities-manager'),
	done: __('All set', 'acrossai-abilities-manager'),
};

/**
 * Wizard chrome.
 *
 * @param {Object}   props              Component props.
 * @param {string}   props.step         Current step id.
 * @param {number}   props.displayIndex Position among visible steps.
 * @param {number}   props.totalSteps   Count of visible steps.
 * @param {boolean}  props.isLoading    Whether a request is in flight.
 * @param {boolean}  props.canAdvance   Whether Continue is enabled.
 * @param {Object}   props.footerAction Extra footer action(s).
 * @param {boolean}  props.hideContinue Whether to hide Continue.
 * @param {Element}  props.belowFooter  Content rendered after the footer.
 * @param {boolean}  props.isTerminal   Whether Continue should read "Finish".
 * @param {Function} props.onBack       Back handler.
 * @param {Function} props.onAdvance    Continue handler.
 * @param {Function} props.onExit       Exit handler.
 * @param {Element}  props.children     Step content.
 * @return {Element} The wizard shell.
 */
const StepLayout = ({
	step,
	displayIndex,
	totalSteps,
	isLoading = false,
	canAdvance = true,
	footerAction = null,
	hideContinue = false,
	belowFooter = null,
	isTerminal = false,
	onBack,
	onAdvance,
	onExit,
	children,
}) => {
	const contentRef = useRef(null);
	const liveRef = useRef(null);
	const bootstrap = window.acrossaiQuickConnect || {};

	const isDone = step === 'done';
	const backDisabled = step === '1' || isDone;

	const footerActions = footerAction
		? [].concat(footerAction).filter(Boolean)
		: [];
	const loadingAction = footerActions.find((action) => action.isLoading);
	// Declared before renderFooterAction, which closes over it.
	const busy = isLoading || !!loadingAction;

	// A secondary action is an aside — "go and look at this" — so it sits between
	// Back and Continue, leaving Continue the primary, rightmost button. A footer
	// action with no variant is the screen's real call to action (screen 6's
	// "check again"), so it trails Continue and takes the primary styling.
	const leadingActions = footerActions.filter(
		(action) => action.variant === 'secondary'
	);
	const trailingActions = footerActions.filter(
		(action) => action.variant !== 'secondary'
	);

	/**
	 * Render one footer action.
	 *
	 * Shared by the leading and trailing slots so the two never drift into
	 * looking like different kinds of button.
	 *
	 * @param {Object} action Footer action descriptor.
	 * @return {Element} Anchor for link actions, button otherwise.
	 */
	const renderFooterAction = (action) =>
		action.href ? (
			<a
				key={action.label}
				className={
					action.variant === 'secondary'
						? 'qs-btn qs-btn--secondary'
						: 'qs-btn'
				}
				href={action.href}
				target={action.target || undefined}
				rel={
					action.target === '_blank'
						? 'noopener noreferrer'
						: undefined
				}
			>
				{action.label}
			</a>
		) : (
			<button
				key={action.label}
				type="button"
				className={
					action.variant === 'secondary'
						? 'qs-btn qs-btn--secondary'
						: 'qs-btn'
				}
				aria-disabled={action.disabled || busy}
				onClick={action.disabled || busy ? undefined : action.onClick}
			>
				{action.label}
			</button>
		);

	const progressPct = Math.min(
		100,
		Math.max(0, Math.round((displayIndex / Math.max(1, totalSteps)) * 100))
	);

	// Move focus into the new screen and announce it. The delay lets the step
	// render before the live region is written, otherwise the announcement can
	// be swallowed.
	useEffect(() => {
		// The heading, not the first focusable. Focusing whatever happens to be
		// first put the user on screen 2's "Watch on YouTube ↗" link — pressing
		// Enter straight after Continue would have left the site — and told them
		// nothing about where they had arrived. A heading reads the screen's
		// title and starts them at the top of the new content.
		//
		// tabindex is applied here rather than in each step so no screen can
		// forget it. -1 keeps the heading out of the tab order; it is a focus
		// target, not a stop.
		const heading = contentRef.current?.querySelector('.qs__step-title');

		if (heading) {
			heading.setAttribute('tabindex', '-1');
			heading.focus();
		} else {
			// A screen with no title still needs focus moved out of the footer,
			// or the next Tab would resume from the button that was just left.
			contentRef.current
				?.querySelector(
					'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
				)
				?.focus();
		}

		const timer = setTimeout(() => {
			if (!liveRef.current) {
				return;
			}

			liveRef.current.textContent = sprintf(
				/* translators: 1: current step number, 2: total steps, 3: step title. */
				__('Step %1$d of %2$d, %3$s', 'acrossai-abilities-manager'),
				displayIndex,
				totalSteps,
				STEP_TITLES[step] || ''
			);
		}, 100);

		return () => clearTimeout(timer);
	}, [step, displayIndex, totalSteps]);

	const backLocked = backDisabled || busy;
	const continueLocked =
		!canAdvance || busy || footerActions.some((action) => action.disabled);

	return (
		<>
			{busy && (
				<div
					className="qs__initial-loading qs__initial-loading--overlay"
					role="alert"
					aria-live="assertive"
					aria-busy="true"
				>
					{bootstrap.iconUrl && (
						<img
							className="qs__initial-loading-icon"
							src={bootstrap.iconUrl}
							alt=""
							aria-hidden="true"
						/>
					)}
					<span className="qs__sr-only">
						{loadingAction?.label ||
							__('Working…', 'acrossai-abilities-manager')}
					</span>
				</div>
			)}

			<div
				className="qs__progress"
				role="progressbar"
				aria-valuenow={displayIndex}
				aria-valuemin={1}
				aria-valuemax={totalSteps}
				aria-label={__('Wizard progress', 'acrossai-abilities-manager')}
			>
				<span
					className="qs__progress-fill"
					style={{ width: `${progressPct}%` }}
				/>
			</div>

			<header className="qs__header">
				{bootstrap.logoUrl && (
					<img
						className="qs__header-logo"
						src={bootstrap.logoUrl}
						alt="AcrossAI"
					/>
				)}
				<span className="qs__header-title">
					{__('Quick Connect', 'acrossai-abilities-manager')}
				</span>
				<a
					className="qs__header-consult"
					href="https://acrossai.co/consultations/"
					target="_blank"
					rel="noopener noreferrer"
				>
					{__('Free Consultations ↗', 'acrossai-abilities-manager')}
				</a>
				{!isDone && (
					/*
					 * A button, not an anchor: this exits the wizard, it does not
					 * navigate to a URL. The sibling uses <a href="#"> with
					 * preventDefault, which is an anchor doing a button's job —
					 * it lands in the tab order announcing itself as a link, and
					 * jsx-a11y flags it. `.qs__header-exit` carries a button
					 * reset so the rendered result is identical (SC-005).
					 */
					<button
						type="button"
						className="qs__header-exit"
						onClick={() => onExit?.()}
					>
						{__('Exit setup', 'acrossai-abilities-manager')}
					</button>
				)}
			</header>

			<div className="qs__content" ref={contentRef}>
				{children}

				{!isDone && (
					<footer className="qs__footer">
						<button
							type="button"
							className="qs-btn qs-btn--secondary"
							disabled={backLocked}
							aria-disabled={backLocked}
							onClick={backLocked ? undefined : onBack}
						>
							{__('Back', 'acrossai-abilities-manager')}
						</button>

						{leadingActions.map(renderFooterAction)}

						{!hideContinue && (
							<button
								type="button"
								className={
									trailingActions.length
										? 'qs-btn qs-btn--secondary'
										: 'qs-btn'
								}
								aria-disabled={continueLocked}
								onClick={continueLocked ? undefined : onAdvance}
							>
								{isTerminal
									? __('Finish', 'acrossai-abilities-manager')
									: __(
											'Continue',
											'acrossai-abilities-manager'
										)}
							</button>
						)}

						{trailingActions.map(renderFooterAction)}
					</footer>
				)}

				{!isDone && belowFooter}
			</div>

			<div
				className="qs__sr-only"
				ref={liveRef}
				aria-live="polite"
				aria-atomic="true"
			/>
		</>
	);
};

export default StepLayout;
