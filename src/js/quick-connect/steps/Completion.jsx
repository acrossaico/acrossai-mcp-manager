/**
 * F069 T040 — Completion screen.
 *
 * Renders after Step 5's Finish button fires POST /complete + navigates
 * to ?step=done. Summarises the four wizard choices + three CTAs.
 *
 * Continue button is hidden by StepLayout when step === 'done' (per FR-024).
 *
 * @package AcrossAI_MCP_Manager
 */

import { useMemo, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { CheckIcon } from '../components/icons.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useWizardRouter from '../hooks/useWizardRouter.js';

const METHOD_LABELS = {
	connectors: 'One-click OAuth (Connectors)',
	client: 'MCP Client (npx bridge)',
	npm: 'npm CLI',
	wpcli: 'WP-CLI (STDIO)',
};

const Completion = () => {
	const { state, complete, refetch } = useWizardState();
	const router = useWizardRouter();
	const bootstrap = window.acrossaiMcpQuickConnect || {};

	// Fire /complete once on mount to clear the scratchpad server-side.
	// If the user then clicks "Set up another server" we refetch state so
	// they start fresh.
	useEffect( () => {
		complete();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const server = useMemo(
		() =>
			state.servers.find( ( s ) => s.id === state.wizardState.server_id ),
		[ state.servers, state.wizardState.server_id ]
	);

	const summaryRows = [
		{
			label: __( 'Server', 'acrossai-mcp-manager' ),
			value: server ? server.name : '—',
		},
		{
			label: __( 'Access', 'acrossai-mcp-manager' ),
			value: state.wizardState.access_saved
				? __( 'Rule configured', 'acrossai-mcp-manager' )
				: __( 'Administrators only (default)', 'acrossai-mcp-manager' ),
		},
		{
			label: __( 'Abilities', 'acrossai-mcp-manager' ),
			// Prefer the DB-authoritative enabledForServer count over the
			// scratchpad flag — the user may have enabled abilities from
			// the "Configure abilities one-by-one" tab (which bypasses
			// abilities_saved), and either way the count reflects reality.
			value:
				state.abilities.enabledForServer !== null &&
				state.abilities.enabledForServer > 0
					? /* translators: 1: enabled ability count, 2: total available */
					  __( '%1$d of %2$d enabled', 'acrossai-mcp-manager' )
							.replace( '%1$d', String( state.abilities.enabledForServer ) )
							.replace( '%2$d', String( state.abilities.total ) )
					: __( 'None enabled', 'acrossai-mcp-manager' ),
		},
		{
			label: __( 'Connected via', 'acrossai-mcp-manager' ),
			value: METHOD_LABELS[ state.wizardState.method ] || '—',
		},
	];

	const gotoServer = () => {
		if ( bootstrap.adminUrl && server ) {
			window.location.href = `${ bootstrap.adminUrl }&action=edit&server=${ server.id }`;
		}
	};

	const setupAnother = async () => {
		await refetch();
		router.goTo( '1' );
	};

	return (
		<div style={ { textAlign: 'center', padding: '20px 0' } }>
			<div
				style={ {
					width: 60,
					height: 60,
					borderRadius: '50%',
					background: '#edfaef',
					border: '2px solid #4ab866',
					display: 'inline-flex',
					alignItems: 'center',
					justifyContent: 'center',
					marginBottom: 20,
					color: '#4ab866',
				} }
				aria-hidden="true"
			>
				<CheckIcon size={ 32 } />
			</div>

			<h2 className="qs__step-title" style={ { textAlign: 'center' } }>
				{ __( "You're all set!", 'acrossai-mcp-manager' ) }
			</h2>

			<div
				style={ {
					border: '1px solid #ddd',
					borderRadius: 2,
					margin: '24px auto',
					maxWidth: 480,
					textAlign: 'left',
				} }
			>
				{ summaryRows.map( ( row ) => (
					<div
						key={ row.label }
						style={ {
							display: 'grid',
							gridTemplateColumns: '20px 140px 1fr',
							gap: 12,
							alignItems: 'center',
							padding: '12px 16px',
							borderBottom: '1px solid #f0f0f0',
						} }
					>
						<span
							style={ { color: '#4ab866', fontSize: 13, fontWeight: 700 } }
							aria-hidden="true"
						>
							✓
						</span>
						<span style={ { fontSize: 12.5, color: '#757575' } }>
							{ row.label }
						</span>
						<span style={ { fontSize: 13, fontWeight: 600 } }>
							{ row.value }
						</span>
					</div>
				) ) }
			</div>

			<div style={ { display: 'flex', justifyContent: 'center', gap: 12, flexWrap: 'wrap' } }>
				<button
					type="button"
					className="qs-btn"
					onClick={ gotoServer }
					disabled={ ! server }
				>
					{ __( 'Go to server dashboard', 'acrossai-mcp-manager' ) }
				</button>
				<button
					type="button"
					className="qs-btn qs-btn--secondary"
					onClick={ setupAnother }
				>
					{ __( 'Set up another server', 'acrossai-mcp-manager' ) }
				</button>
				<button
					type="button"
					className="qs-btn qs-btn--link"
					onClick={ router.exit }
				>
					{ __( 'Dismiss', 'acrossai-mcp-manager' ) }
				</button>
			</div>

			<p style={ { fontSize: 12, color: '#757575', marginTop: 24 } }>
				{ __(
					'You can re-run this wizard anytime from the top admin bar.',
					'acrossai-mcp-manager'
				) }
			</p>
		</div>
	);
};

export default Completion;
