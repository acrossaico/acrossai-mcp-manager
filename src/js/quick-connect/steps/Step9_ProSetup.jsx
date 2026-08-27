/**
 * F074 — Step 9: the single "get AcrossAI Pro working" screen.
 *
 * Replaces the old activation-only step 9. It covers all three remaining
 * blockers and adapts its copy + primary action to whichever one the operator
 * is on:
 *
 *   plugins.acrossaiPro === 'missing'   → install (trial just started)
 *   plugins.acrossaiPro === 'inactive'  → activate
 *   active + acrossaiProLicensed false  → connect a licence / trial
 *
 * Reached either by an explicit `router.goTo( '9' )` from Step 8 once
 * the Freemius checkout reports the trial started, or by the normal forward
 * walk once Pro shows up on the site. App.jsx's skipProSetup predicate keeps
 * it up until Pro is BOTH active and licensed — acrossai-pro registers zero
 * connector profiles without a licence, so "active" alone must not let the
 * wizard move on to the Connectors screen.
 *
 * Why a real step rather than local state on Step 8: the trial-started screen
 * used to be a `useState` flag, which meant a page reload (or the operator
 * closing the tab while installing) silently dropped them back to the pricing
 * pitch as if they had never started a trial. Parking the state in the URL
 * makes it survive reloads, deep links and browser history.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useWizardRouter from '../hooks/useWizardRouter.js';
import useAdvanceGuard, {
	useFooterAction,
	useHideContinue,
} from '../hooks/useAdvanceGuard.js';

// Walkthrough covering install → activate → licence end to end, i.e. whatever
// the operator still has left to do. Video ID from the share link
// (https://youtu.be/aJRH_X0qooo). autoplay=1 requires mute=1 in
// every modern browser — audio autoplay is blocked without a user gesture.
const WALKTHROUGH_EMBED =
	'https://www.youtube.com/embed/aJRH_X0qooo?autoplay=1&mute=1&rel=0';

const Step9_ProSetup = () => {
	const { state } = useWizardState();
	const router = useWizardRouter();
	const bootstrap = window.acrossaiMcpQuickConnect || {};
	const proState = state.plugins.acrossaiPro;

	// Step 8 hands off with ?mode=trial after a completed Freemius checkout.
	// Reaching this step via plain Continue leaves mode unset — in that case
	// the operator has NOT started a trial, so claiming they have (and telling
	// them to check their email for a download link) would be a lie.
	const fromTrial = 'trial' === router.mode;

	// 'install' → 'activate' → 'license'. App.jsx forwards off this step
	// entirely once Pro is active AND licensed, so there is no fourth phase.
	const phase =
		'missing' === proState
			? 'install'
			: 'inactive' === proState
			? 'activate'
			: 'license';

	// Continue never unlocks here — App.jsx auto-forwards off this step once
	// Pro is active and licensed. Hide it rather than render a permanently
	// dead button that invites confused clicking.
	useAdvanceGuard( false );
	useHideContinue( true );

	// Back · Add new plugin · Reload, all on one footer row. One destination
	// for every phase — WordPress's Add Plugins screen.
	const footerActions = useMemo( () => {
		return [
			{
				label: __( 'Add new plugin', 'acrossai-mcp-manager' ),
				href: bootstrap.pluginInstallUrl || 'plugin-install.php',
				variant: 'secondary',
				// href (not onClick + window.open) so popup blockers can't
				// turn this into a dead button — see StepLayout's anchor
				// branch.
				target: '_blank',
			},
			{
				label:
					'install' === phase
						? __(
								'Reload — I installed it',
								'acrossai-mcp-manager'
						  )
						: __(
								"I've activated it — reload",
								'acrossai-mcp-manager'
						  ),
				// Full reload (not refetch) so a freshly-activated plugin's
				// own admin assets and Freemius state load too — a bare
				// refetch can read stale licence data.
				onClick: () => window.location.reload(),
			},
		];
	}, [ phase, bootstrap.pluginInstallUrl ] );
	useFooterAction( footerActions );

	const title = {
		install: fromTrial
			? __(
					'Trial started — install AcrossAI Pro',
					'acrossai-mcp-manager'
			  )
			: __( 'Install AcrossAI Pro', 'acrossai-mcp-manager' ),
		activate: __( 'Activate AcrossAI Pro', 'acrossai-mcp-manager' ),
		license: __(
			'Enter your AcrossAI Pro licence',
			'acrossai-mcp-manager'
		),
	}[ phase ];

	const instructions = {
		install: fromTrial
			? __(
					'Check your email for the download link, install AcrossAI Pro on this site, then come back to this tab and click "Reload — I installed it" below.',
					'acrossai-mcp-manager'
			  )
			: __(
					'Install AcrossAI Pro on this site and activate it with your licence key, then come back to this tab and click "Reload — I installed it" below.',
					'acrossai-mcp-manager'
			  ),
		activate: __(
			'AcrossAI Pro is on this site but not activated yet. Activate it and enter your licence key, then come back to this tab and click "I\'ve activated it — reload" below.',
			'acrossai-mcp-manager'
		),
		license: __(
			'AcrossAI Pro is active but no licence is connected yet. Enter your licence key, then come back to this tab and click "I\'ve activated it — reload" below.',
			'acrossai-mcp-manager'
		),
	}[ phase ];

	return (
		<div>
			<h2 className="qs__step-title">{ title }</h2>

			{ 'install' === phase && fromTrial && (
				<div style={ { marginBottom: 20 } }>
					<Notice status="success">
						<p style={ { margin: 0 } }>
							{ __(
								'Trial started — check your email for the download link. Install AcrossAI Pro on this site, activate it, then return here — the wizard will detect it automatically.',
								'acrossai-mcp-manager'
							) }
						</p>
					</Notice>
				</div>
			) }

			{ /* Same card container as Step 8's pricing panel (kept for visual
			     continuity), body swapped for a responsive 16:9 YouTube embed.
			     autoplay + mute so browsers actually start playback. */ }
			<div className="qs__pro-card qs__pro-card--video">
				<div className="qs__pro-card__video">
					<iframe
						src={ WALKTHROUGH_EMBED }
						title={ __(
							'AcrossAI Pro walkthrough',
							'acrossai-mcp-manager'
						) }
						allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
						allowFullScreen
					/>
				</div>
			</div>

			<Notice status="info">
				{ instructions }
				{ ' ' }
				{ __(
					'The wizard moves on by itself once Pro is active and licensed.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		</div>
	);
};

export default Step9_ProSetup;
