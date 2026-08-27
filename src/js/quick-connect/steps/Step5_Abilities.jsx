/**
 * F069 — Step 5: Abilities picker.
 *
 * By the time this step renders, Step 4 (Abilities Manager plugin gate)
 * has already ensured `state.plugins.abilitiesManager === 'active'` —
 * otherwise App.jsx keeps the user parked on Step 4 until they install /
 * activate. The active-plugin variant is the primary path.
 *
 * Layout:
 *   - Giant "X / Y" count ("enabled for this server / total")
 *   - "Configure abilities one-by-one ↗" secondary link (opens the
 *     Abilities admin tab in a new browser tab)
 *   - Footer button "Enable all and continue" (registered via
 *     useFooterAction) — runs enable-all POST → refetch → advance in one
 *     click. Hidden when all are already enabled; the plain Continue
 *     button handles that case.
 *
 * The inactive/missing branch is retained as a defensive fallback in case
 * a user deep-links to ?step=5 without going through Step 4.
 *
 * No advance guard — enabling abilities is optional; Continue is always
 * allowed and just walks forward without touching abilities.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import { useFooterAction } from '../hooks/useAdvanceGuard.js';

const CORE_ABILITIES = [
	'core/get-environment-info',
	'core/get-site-info',
	'core/get-user-info',
];

const Step5_Abilities = () => {
	const { state, saveStep, isLoading } = useWizardState();
	const [ enabling, setEnabling ] = useState( false );
	const bootstrap = window.acrossaiMcpQuickConnect || {};
	const managerState = state.plugins.abilitiesManager; // 'active' | 'inactive' | 'missing'
	const managerActive = managerState === 'active';
	const managerInactive = managerState === 'inactive';
	const managerActivateUrl = state.plugins.abilitiesManagerActivateUrl || '';
	const serverId = state.wizardState.server_id;

	const total = state.abilities.total;
	const enabledForServer = state.abilities.enabledForServer;
	const allEnabled =
		total > 0 &&
		enabledForServer !== null &&
		enabledForServer >= total;

	const handleEnableAllAndContinue = useCallback( async () => {
		setEnabling( true );
		try {
			// saveStep response already carries a fresh abilities count
			// (QuickConnectController pipes it in whenever the scratchpad has
			// a server_id). No manual refetch needed — the reducer merges
			// abilities on SAVE_STEP_SUCCESS, App.jsx's skipAbilities flips
			// to true, and the auto-skip effect forwards past this step.
			// No manual router.advance() here to avoid the double-jump
			// race that Step 5 had before auto-skip was in place.
			await saveStep( 5, { enable_all_abilities: true } );
		} finally {
			setEnabling( false );
		}
	}, [ saveStep ] );

	// Register the third footer button. Hidden entirely when all abilities
	// are already enabled — plain Continue is enough in that case.
	const footerAction = useMemo(
		() =>
			managerActive && ! allEnabled
				? {
					label: __( 'Enable all and continue', 'acrossai-mcp-manager' ),
					onClick: handleEnableAllAndContinue,
					isLoading: enabling || isLoading,
					disabled: enabling || isLoading,
				  }
				: null,
		[ managerActive, allEnabled, handleEnableAllAndContinue, enabling, isLoading ]
	);
	useFooterAction( footerAction );

	const buildAbilitiesTabUrl = () => {
		if ( ! bootstrap.adminUrl || ! serverId ) return '#';
		return `${ bootstrap.adminUrl }&action=edit&server=${ serverId }&tab=abilities`;
	};

	if ( ! managerActive ) {
		return (
			<div>
				<h2 className="qs__step-title">
					{ __( 'What should AI be able to do?', 'acrossai-mcp-manager' ) }
				</h2>

				<div style={ { display: 'flex', alignItems: 'flex-end', gap: 16, justifyContent: 'center', margin: '20px 0' } }>
					<span style={ { fontSize: 60, fontWeight: 700, lineHeight: 0.9 } }>
						{ enabledForServer !== null
							? `${ enabledForServer } / ${ total }`
							: total }
					</span>
					<div style={ { paddingBottom: 8 } }>
						<div style={ { fontSize: 15, fontWeight: 600 } }>
							{ enabledForServer !== null
								? __( 'enabled for this server', 'acrossai-mcp-manager' )
								: __( 'abilities available', 'acrossai-mcp-manager' ) }
						</div>
						<div style={ { fontSize: 13, color: '#757575' } }>
							{ __(
								'WordPress ships with only 3 by default.',
								'acrossai-mcp-manager'
							) }
						</div>
					</div>
				</div>

				<ul style={ { listStyle: 'none', padding: 0, marginBottom: 24, border: '1px solid #ddd', borderRadius: 2 } }>
					{ CORE_ABILITIES.map( ( slug ) => (
						<li
							key={ slug }
							style={ {
								padding: '11px 14px',
								borderBottom: '1px solid #f0f0f0',
								fontFamily: 'monospace',
								fontSize: 12,
							} }
						>
							• { slug }
						</li>
					) ) }
				</ul>

				<div
					style={ {
						border: '1px solid #c4b5fd',
						background: '#faf9ff',
						padding: 20,
						borderRadius: 2,
						marginBottom: 20,
					} }
				>
					<h3 style={ { margin: '0 0 12px', fontSize: 15, fontWeight: 600, color: '#312e81' } }>
						{ managerInactive
							? __(
									'Activate AcrossAI Abilities Manager to unlock 350+ abilities',
									'acrossai-mcp-manager'
							  )
							: __(
									'Unlock 350+ abilities with AcrossAI Abilities Manager',
									'acrossai-mcp-manager'
							  ) }
					</h3>
					<p style={ { fontSize: 13, lineHeight: 1.65, color: '#4c1d95', margin: '0 0 16px' } }>
						{ managerInactive
							? __(
									'The plugin is already installed on this site. Activate it now to add 350+ new abilities your AI client can use.',
									'acrossai-mcp-manager'
							  )
							: __(
									'Create pages, update content, install plugins, update WordPress core, manage your entire site — all from your AI client.',
									'acrossai-mcp-manager'
							  ) }
					</p>
					<div style={ { display: 'flex', gap: 14, alignItems: 'center' } }>
						{ managerInactive && managerActivateUrl ? (
							<a
								className="qs-btn"
								href={ managerActivateUrl }
								style={ { background: '#4f46e5', borderColor: '#4f46e5' } }
							>
								{ __( 'Activate Abilities Manager', 'acrossai-mcp-manager' ) }
							</a>
						) : (
							<a
								className="qs-btn"
								href="https://wordpress.org/plugins/acrossai-abilities-manager/"
								target="_blank"
								rel="noopener noreferrer"
								style={ { background: '#4f46e5', borderColor: '#4f46e5' } }
							>
								{ __( 'Install from WordPress.org', 'acrossai-mcp-manager' ) }
							</a>
						) }
						<a
							href="https://acrossai.co/use-cases/"
							target="_blank"
							rel="noopener noreferrer"
							style={ { color: '#4f46e5', textDecoration: 'none' } }
						>
							{ __( 'View case studies →', 'acrossai-mcp-manager' ) }
						</a>
					</div>
				</div>

				<Notice status="info">
					{ __(
						'By default, all abilities registered by Abilities Manager can be accessed by users with the manage_options capability (admins only). You can broaden this per-ability in the Access Control step.',
						'acrossai-mcp-manager'
					) }
				</Notice>
			</div>
		);
	}

	// Variant B — Abilities Manager active. In-page "Enable all" button
	// removed; the equivalent primary action lives in the wizard footer as
	// "Enable all and continue" (see useFooterAction above).
	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'What should AI be able to do?', 'acrossai-mcp-manager' ) }
			</h2>

			<div style={ { display: 'flex', alignItems: 'flex-end', gap: 16, justifyContent: 'center', margin: '20px 0' } }>
				<span style={ { fontSize: 60, fontWeight: 700, lineHeight: 0.9 } }>
					{ enabledForServer !== null
						? `${ enabledForServer } / ${ total }`
						: total }
				</span>
				<div style={ { paddingBottom: 8 } }>
					<div style={ { fontSize: 15, fontWeight: 600 } }>
						{ enabledForServer !== null
							? __( 'enabled for this server', 'acrossai-mcp-manager' )
							: __( 'abilities available', 'acrossai-mcp-manager' ) }
					</div>
					<div style={ { fontSize: 13, color: '#757575' } }>
						{ __( 'From AcrossAI Abilities Manager.', 'acrossai-mcp-manager' ) }
					</div>
				</div>
			</div>

			{ allEnabled && (
				<Notice status="success">
					{ __(
						'All abilities enabled for this server ✓',
						'acrossai-mcp-manager'
					) }
				</Notice>
			) }

			<div style={ { display: 'flex', justifyContent: 'center', marginBottom: 20 } }>
				<a
					className="qs-btn qs-btn--secondary"
					href={ buildAbilitiesTabUrl() }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Configure abilities one-by-one ↗', 'acrossai-mcp-manager' ) }
				</a>
			</div>

			<Notice status="info">
				{ __(
					'By default, all abilities registered by Abilities Manager can be accessed by users with the manage_options capability (admins only). You can broaden this per-ability in the Access Control step.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		</div>
	);
};

export default Step5_Abilities;
