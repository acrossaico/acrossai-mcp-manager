/**
 * F069 — Step 8: AcrossAI Pro pitch page.
 *
 * Only rendered when the user picked "One-click OAuth (Connectors)" on Step 7
 * AND Pro is not yet active-and-licensed. App.jsx's skipProPromo predicate
 * handles the show/skip; this component assumes the state has been vetted.
 *
 * Full-page pitch that pairs the trial promo bar (already on Step 7) with
 * the value proposition and a "Start free trial" CTA to the pricing page.
 * Shown until acrossai-pro is fully usable — installed AND licensed — so the
 * trial CTA stays reachable for someone who installed the plugin but never
 * connected a licence. Continue walks on to Step 9 for the install / activate
 * / licence instructions.
 *
 * F074 — once the Freemius checkout reports the trial as started, this step
 * hands off to step 9 (Step9_ProSetup.jsx) rather than swapping its own body.
 * That screen used to be a local `useState` flag here, which meant a page
 * reload dropped the operator back onto the pricing pitch as if they had never
 * started a trial. The URL now carries that state.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useWizardRouter from '../hooks/useWizardRouter.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';

const TRIAL_URL = 'https://acrossai.co/pricing/#pricing';

const Step8_ProPromo = () => {
	const { state, refetch } = useWizardState();
	const router = useWizardRouter();
	const trialEndDate = state.plugins.trialEndDate || '';

	// Continue is ENABLED here. This step now stays visible until Pro is fully
	// usable (installed AND licensed), not just while it's missing — so an
	// operator who already has a licence key must be able to walk past the
	// pitch to Step 9 and enter it. A disabled Continue would strand them.
	// App.jsx's auto-skip still forwards past both 8 and 9 the moment Pro is
	// active and licensed.
	useAdvanceGuard( true );

	// Freemius fires purchaseCompleted AND success for the same checkout.
	// Without this latch we'd push two identical history entries, so Back
	// would need two presses to get off the trial screen.
	const handedOffRef = useRef( false );

	const goToTrialStarted = useCallback( () => {
		if ( handedOffRef.current ) {
			return;
		}
		handedOffRef.current = true;
		// mode=trial tells Step 9 the operator got here from a completed
		// checkout, so it can show the "Trial started — check your email"
		// confirmation. Arriving via plain Continue leaves mode unset and
		// Step 9 shows neutral install copy instead.
		router.goTo( '9', null, 'trial' );
	}, [ router ] );

	// F074 — Freemius Checkout trial modal. Uses the credentials shipped
	// via wp_localize_script in admin/Main.php::maybe_enqueue_quick_connect_app.
	// window.FS is provided by the acrossai-mcp-manager-freemius-checkout
	// script enqueued alongside the wizard bundle. If either the credentials
	// or the FS global are missing (script blocked by ad-blocker / CSP / net
	// failure), silently fall back to opening the external pricing page in a
	// new tab — matches the pre-F074 behaviour, zero regression risk.
	const handleStartTrial = useCallback( ( event ) => {
		event.preventDefault();
		const bootstrap = window.acrossaiMcpQuickConnect || {};
		const creds = bootstrap.freemiusPro || {};
		const { product_id, public_key, plan_id } = creds;
		if ( ! product_id || typeof window.FS?.Checkout !== 'function' ) {
			window.open( TRIAL_URL, '_blank', 'noopener' );
			return;
		}
		const handler = new window.FS.Checkout( { product_id, public_key } );
		handler.open( {
			name: 'AcrossAI Pro',
			licenses: 1,
			plan_id,
			trial: 'free',
			purchaseCompleted: goToTrialStarted,
			success: () => {
				goToTrialStarted();
				// Refetch so if Freemius has already flipped plugins.acrossaiPro
				// (e.g. their WP SDK on this site auto-detected the trial),
				// App.jsx's auto-skip effect walks the user straight off
				// Step 9 to the Connectors screen instead of parking them.
				refetch();
			},
			cancel: () => { /* user closed the modal — no-op */ },
		} );
	}, [ refetch, goToTrialStarted ] );

	// NOTE: no refetch-on-mount. useWizardState already registers global
	// visibilitychange + focus + popstate listeners that catch the "user
	// installed Pro in another tab and came back" case. A local refetch
	// here would flip state.status to 'loading', which triggers App.jsx's
	// early-return to the initial-loading icon → this step unmounts → the
	// effect re-fires on remount → infinite loop / blank screen.

	return (
		<div>
			<h2 className="qs__step-title">
				{ __(
					'One-click OAuth needs AcrossAI Pro',
					'acrossai-mcp-manager'
				) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'Paste one URL into Claude, ChatGPT, Grok, or Gemini and approve — no config files, no JSON. This connector requires the AcrossAI Pro plugin.',
					'acrossai-mcp-manager'
				) }
			</p>

			{ /* Pricing-card layout mirroring the reference marketing card:
			     hero title + outlined CTA + shield-emoji fine print, a soft
			     divider, then the blue-check bullet list. Every text string
			     is preserved verbatim; only the layout + styling changed. */ }
			<div className="qs__pro-card">
				<div className="qs__pro-card__hero">
					<div className="qs__pro-card__title">
						{ __( '30 days free. No credit card.', 'acrossai-mcp-manager' ) }
					</div>

					<button
						type="button"
						className="qs__pro-card__cta"
						onClick={ handleStartTrial }
					>
						{ __( 'Start free trial', 'acrossai-mcp-manager' ) }
					</button>

					<p className="qs__pro-card__fine">
						<span aria-hidden="true">🛡️</span>{ ' ' }
						{ trialEndDate
							? sprintf(
									/* translators: %s: date string */
									__( 'Start today — free through %s.', 'acrossai-mcp-manager' ),
									trialEndDate
							  )
							: __( 'Start today.', 'acrossai-mcp-manager' ) }
					</p>
				</div>

				<hr className="qs__pro-card__divider" />

				<ul className="qs__pro-card__bullets">
					<li>
						{ __(
							'One-click connectors for Claude, ChatGPT, Grok, Gemini & Cursor.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __(
							'Unlimited actions — never metered.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __(
							'Runs on your own server — no third-party cloud.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __(
							'Access Control — restrict by membership, not just role.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __(
							'1 year of updates.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __( 'Email support.', 'acrossai-mcp-manager' ) }
					</li>
				</ul>
			</div>

			<Notice status="info">
				{ __(
					'Already have a licence key? Click Continue and the next step will walk you through installing, activating and entering it.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		</div>
	);
};

export default Step8_ProPromo;
