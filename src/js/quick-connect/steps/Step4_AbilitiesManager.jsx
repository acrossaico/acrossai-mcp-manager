/**
 * F069 — Step 4: AcrossAI Abilities Manager plugin gate.
 *
 * Renders only when `state.plugins.abilitiesManager !== 'active'` — App.jsx
 * auto-skips this step when the plugin is already active. Two branches:
 *
 *   - 'missing'  → footer "Install & Continue" primary. Click → POST
 *                  /quick-connect/install-plugin with slug
 *                  `acrossai-abilities-manager`; the backend uses
 *                  Plugin_Upgrader to fetch from WP.org, extract, and
 *                  activate. On success we refetch /state — the plugin
 *                  becomes 'active' and App.jsx's skipAbilitiesGate flips
 *                  to true, so the auto-skip effect walks the user past
 *                  this step to Step 5 (or further if it's also skipped).
 *
 *   - 'inactive' → footer "Activate & Continue" primary. Same endpoint —
 *                  backend detects the plugin is already installed and
 *                  only runs activate_plugin(). Same code path so the
 *                  user never leaves the wizard.
 *
 * Advance guard: TRUE — the user can Continue without installing/activating.
 * Skipping means Step 5 renders its "manager inactive/missing" variant
 * (the 3 WP core abilities + the promo card), which is a valid state.
 *
 * Visual pattern matches Step 8 (Pro pitch) — same "gate" grammar so both
 * external-action screens feel consistent: centered layout, headline stat
 * in the highlighted card, primary CTA in the wizard footer, info notice
 * below the gate card.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useCallback, useMemo, createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import { useFooterAction } from '../hooks/useAdvanceGuard.js';

const ABILITIES_MANAGER_SLUG = 'acrossai-abilities-manager';
const WP_ORG_URL = 'https://wordpress.org/plugins/acrossai-abilities-manager/';

// Ability categories shipped by acrossai-abilities-manager. Rendered as a
// compact 2-column grid inside the gate card so the operator can see the
// breadth of what installing unlocks. Keep sorted alphabetically so scanning
// is predictable; the array order is the render order.
const ABILITY_CATEGORIES = [
	'Blocks',
	'Cache',
	'Comments',
	'Content Search',
	'Core',
	'Cron',
	'Database',
	'File Manager',
	'Media',
	'Plugins',
	'Rank Math',
	'Settings',
	'Site Health',
	'Themes',
	'Users',
	'Widgets',
];

const Step4_AbilitiesManager = () => {
	const { state, refetch } = useWizardState();
	const [ working, setWorking ] = useState( false );
	const [ error, setError ] = useState( null );

	const managerState = state.plugins.abilitiesManager; // 'missing' | 'inactive' | 'active'
	const isMissing = managerState === 'missing';

	const handleInstallOrActivateAndContinue = useCallback( async () => {
		setWorking( true );
		setError( null );
		try {
			const bootstrap = window.acrossaiMcpQuickConnect || {};
			const restRoot =
				bootstrap.restUrl ||
				'/wp-json/acrossai-mcp-manager/v1/quick-connect';
			await apiFetch( {
				url: `${ restRoot }/install-plugin`,
				method: 'POST',
				data: { slug: ABILITIES_MANAGER_SLUG },
			} );
			// After refetch, state.plugins.abilitiesManager flips to 'active'.
			// App.jsx's skipAbilitiesGate predicate becomes true and the
			// auto-skip effect forwards past this step. No manual
			// router.advance() here to avoid the double-jump race Step 5
			// hit before we consolidated on the auto-skip pattern.
			await refetch();
		} catch ( err ) {
			setError(
				( err && err.message ) ||
					__(
						'Something went wrong. Try again or install the plugin manually.',
						'acrossai-mcp-manager'
					)
			);
		} finally {
			setWorking( false );
		}
	}, [ refetch ] );

	const footerActionLabel = working
		? isMissing
			? __( 'Installing…', 'acrossai-mcp-manager' )
			: __( 'Activating…', 'acrossai-mcp-manager' )
		: isMissing
		? __( 'Install & Continue', 'acrossai-mcp-manager' )
		: __( 'Activate & Continue', 'acrossai-mcp-manager' );

	const footerAction = useMemo(
		() => ( {
			label: footerActionLabel,
			onClick: handleInstallOrActivateAndContinue,
			isLoading: working,
			disabled: working,
		} ),
		[ footerActionLabel, handleInstallOrActivateAndContinue, working ]
	);
	useFooterAction( footerAction );

	// Two-tone brand element interpolated into the heading string via
	// createInterpolateElement — the `<brand/>` placeholder in the __()
	// source keeps the string translator-friendly (they can move the mark
	// around or drop it entirely for languages that don't need a Roman
	// wordmark, and it still renders as one piece rather than three
	// concatenated substrings).
	const brandInline = (
		<span className="qs__brand-mark qs__brand-mark--inline">
			<span className="qs__brand-mark__across">Across</span>
			<span className="qs__brand-mark__ai">AI</span>
		</span>
	);
	const heading = createInterpolateElement(
		isMissing
			? __(
					'Install <brand/> Abilities Manager',
					'acrossai-mcp-manager'
			  )
			: __(
					'Activate <brand/> Abilities Manager',
					'acrossai-mcp-manager'
			  ),
		{ brand: brandInline }
	);

	// The full-screen loading overlay is rendered by <StepLayout> whenever
	// footerAction.isLoading is true (see StepLayout's `busy` computation) —
	// this step just needs to set `working` and the shared overlay takes
	// over. Consistent look with every other saving action in the wizard.
	return (
		<div>
			{ error && (
				<div style={ { marginBottom: 20 } }>
					<Notice status="error">{ error }</Notice>
				</div>
			) }

			<div className="qs__gate-card">
				{ /* Icon SVG from wp_localize_script's `logoUrl`
				     (assets/quick-connect/acrossai-logo.svg). The AcrossAI
				     wordmark is interpolated inline into the heading below,
				     so the standalone wordmark that used to sit here is
				     no longer needed. */ }
				{ ( window.acrossaiMcpQuickConnect || {} ).logoUrl && (
					<img
						className="qs__gate-card__logo"
						src={ window.acrossaiMcpQuickConnect.logoUrl }
						alt=""
						aria-hidden="true"
					/>
				) }
				<h2 className="qs__step-title">{ heading }</h2>

				<div className="qs__gate-categories">
					<p className="qs__gate-categories-label">
						{ __( 'Includes 350+ abilities for:', 'acrossai-mcp-manager' ) }
					</p>
					<ul className="qs__gate-bullets qs__gate-bullets--grid">
						{ ABILITY_CATEGORIES.map( ( name ) => (
							<li key={ name }>{ name }</li>
						) ) }
					</ul>
				</div>

				<div className="qs__gate-actions">
					<a
						className="qs-btn qs-btn--secondary"
						href={ WP_ORG_URL }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'View on WordPress.org ↗', 'acrossai-mcp-manager' ) }
					</a>
				</div>
			</div>

			<div style={ { marginTop: 24 } }>
				<Notice status="info">
					{ __(
						'You can Continue without installing — the wizard will fall back to the 3 abilities WordPress ships with. Or install the plugin now to unlock the full 350+ set. You can broaden per-ability access in the next step.',
						'acrossai-mcp-manager'
					) }
				</Notice>
			</div>
		</div>
	);
};

export default Step4_AbilitiesManager;
