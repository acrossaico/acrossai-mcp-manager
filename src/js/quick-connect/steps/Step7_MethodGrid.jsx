/**
 * F069 — Step 7: connection method picker (2×2 grid).
 *
 * Renders four method cards (Connectors, MCP Client, npm, WP-CLI). Picking
 * a card saves `method` to the scratchpad and advances the wizard — no
 * more inline expansion. The detail screen lives in a dedicated step:
 *   - connectors → step 10 (via step 8 pitch or step 9 activate gate first,
 *                  depending on `state.plugins.acrossaiPro` — App.jsx skip
 *                  predicates route to the right one)
 *   - client     → step 11
 *   - npm        → step 12
 *   - wpcli      → step 13
 *
 * All four cards are always selectable — even the Connectors card when Pro
 * is missing / inactive. The trial promo bar and PAID badge remain to hint
 * that Connectors needs Pro, and the routing layer forwards Connectors
 * picks through the appropriate gate step.
 *
 * Advance guard: canAdvance = wizardState.method !== null. Picking a card
 * both saves the choice AND advances, so the plain Continue button is a
 * belt-and-braces path for users who pick and then return via Back.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import RadioCard from '../components/RadioCard.jsx';
import { LinkIcon, PuzzleIcon, TerminalIcon } from '../components/icons.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';


const METHODS = [
	{
		key: 'connectors',
		title: __( 'One-click OAuth (Connectors)', 'acrossai-mcp-manager' ),
		description: __(
			'Paste one URL into Claude, ChatGPT, Grok, or Gemini and approve. No config files.',
			'acrossai-mcp-manager'
		),
		icon: LinkIcon,
	},
	{
		key: 'client',
		title: __( 'MCP Client', 'acrossai-mcp-manager' ),
		description: __(
			'Paste a JSON config into Claude Desktop, VS Code, Cursor, and more.',
			'acrossai-mcp-manager'
		),
		icon: PuzzleIcon,
	},
	{
		key: 'npm',
		title: __( 'npm (one-line install)', 'acrossai-mcp-manager' ),
		description: __(
			'Run a single npx command — no config files, no JSON.',
			'acrossai-mcp-manager'
		),
		icon: TerminalIcon,
	},
	{
		key: 'wpcli',
		title: __( 'WP-CLI (local subprocess)', 'acrossai-mcp-manager' ),
		description: __(
			'Best for CI or local dev. No network credentials transmitted.',
			'acrossai-mcp-manager'
		),
		icon: TerminalIcon,
	},
];

const Step7_MethodGrid = () => {
	const { state, saveStep } = useWizardState();
	const proState = state.plugins.acrossaiPro; // 'missing' | 'inactive' | 'active'
	const chosenMethod = state.wizardState.method;
	const showRecommendedBadge = proState !== 'active';
	const [ picking, setPicking ] = useState( null );

	useAdvanceGuard( chosenMethod !== null );

	// Pick just SELECTS the method (highlights the card, enables the
	// Continue button) — it deliberately does NOT auto-advance. Users found
	// the auto-jump jarring: clicking a card would instantly whisk them off
	// step 7 with no chance to compare options or read the description.
	//
	// Advance now happens via the shared Continue button in the footer,
	// which calls App.jsx's handleContinue → router.advance({ skips }).
	// By the time the user clicks Continue, state.wizardState.method has
	// propagated and App's memoized `skips` is fresh — no stale-closure
	// race that would need locally-computed skips here.
	const handlePick = useCallback( async ( methodKey ) => {
		setPicking( methodKey );
		try {
			await saveStep( 7, { method: methodKey } );
		} finally {
			setPicking( null );
		}
	}, [ saveStep ] );

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'How do you want to connect?', 'acrossai-mcp-manager' ) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'Pick a connection method. You can add more later from the server\'s edit page.',
					'acrossai-mcp-manager'
				) }
			</p>

			<div
				style={ {
					display: 'grid',
					gridTemplateColumns: 'repeat(2, minmax(0, 1fr))',
					gap: 12,
				} }
				role="radiogroup"
				aria-label={ __(
					'Connection methods',
					'acrossai-mcp-manager'
				) }
			>
				{ METHODS.map( ( m ) => {
					const Icon = m.icon;
					const isConnectors = m.key === 'connectors';
					const isPicking = picking === m.key;

					return (
						<RadioCard
							key={ m.key }
							name="qs-method"
							value={ m.key }
							selected={ chosenMethod === m.key }
							onSelect={
								isPicking ? undefined : () => handlePick( m.key )
							}
							title={
								<>
									<Icon size={ 18 } />{ ' ' }
									{ m.title }
									{ isConnectors && showRecommendedBadge && (
										<span className="qs-card__badge">
											{ __( 'RECOMMENDED', 'acrossai-mcp-manager' ) }
										</span>
									) }
								</>
							}
							subtitle={ m.description }
						/>
					);
				} ) }
			</div>
		</div>
	);
};

export default Step7_MethodGrid;
