/**
 * F069 — Step 1: server picker.
 *
 * Reads state.servers (populated by handle_state). Renders one RadioCard per
 * existing server plus a "+ Create a new server" tile as the last option.
 *
 * Picking an existing server sets `wizardState.server_id` and clears
 * `create_intent` — App.jsx's skip effect then jumps step 2 → 3 on Continue.
 * Picking the create tile sets `create_intent: true` (and clears any prior
 * server_id); Continue then advances to step 2 (the create form).
 *
 * When there are zero existing servers we auto-set create intent up front
 * so the user isn't shown an empty radiogroup.
 *
 * When the user lands on Step 1 for the first time with no prior pick and
 * the seeded "Default MCP Server" exists, we auto-select it so Continue is
 * immediately usable — the common path (accept the default) doesn't need
 * an extra click. Users can still change the pick by clicking any other card.
 *
 * Advance guard: canAdvance = server_id !== null OR create_intent === true.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useMemo, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import RadioCard from '../components/RadioCard.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';

const CREATE_TILE_VALUE = '__create__';

// Kept in sync with DefaultServerSeeder::SLUG on the PHP side. This is the
// slug we prefer when auto-selecting a first-load default; if the seeded
// row is missing (dev environment, manual delete), we fall back to whatever
// server sits at index 0 in the DB-ordered list.
const DEFAULT_SERVER_SLUG = 'mcp-adapter-default-server';

const Step1_ServerPick = () => {
	const { state, saveStep } = useWizardState();
	const selectedId = state.wizardState.server_id;
	const createIntent = !! state.wizardState.create_intent;

	// Zero-server case → auto-set create intent so Continue is immediately
	// available and the user isn't stuck staring at an empty picker.
	useEffect( () => {
		if (
			state.status === 'ready' &&
			state.servers.length === 0 &&
			! createIntent
		) {
			saveStep( 1, { create_intent: true } );
		}
	}, [ state.status, state.servers.length, createIntent, saveStep ] );

	// First-load auto-select — pick the seeded "Default MCP Server" when the
	// user has no prior pick AND no explicit create intent. Runs once per
	// mount; if the user actively picks a different card (which clears the
	// scratchpad's server_id? no — sets it to the other id) the condition
	// stops matching so this effect stops re-firing.
	useEffect( () => {
		if (
			state.status !== 'ready' ||
			selectedId !== null ||
			createIntent ||
			state.servers.length === 0
		) {
			return;
		}
		const preferred =
			state.servers.find( ( s ) => s.slug === DEFAULT_SERVER_SLUG ) ||
			state.servers[ 0 ];
		if ( preferred ) {
			saveStep( 1, { server_id: preferred.id } );
		}
	}, [ state.status, state.servers, selectedId, createIntent, saveStep ] );

	useAdvanceGuard( selectedId !== null || createIntent );

	const handleSelectExisting = async ( serverId ) => {
		await saveStep( 1, { server_id: serverId } );
	};

	const handleSelectCreate = async () => {
		await saveStep( 1, { create_intent: true } );
	};

	const servers = useMemo( () => state.servers || [], [ state.servers ] );

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'Which server should AI connect to?', 'acrossai-mcp-manager' ) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'Pick an existing MCP server, or create a new one for this setup.',
					'acrossai-mcp-manager'
				) }
			</p>

			<div
				role="radiogroup"
				aria-label={ __( 'MCP servers', 'acrossai-mcp-manager' ) }
			>
				{ servers.map( ( server ) => (
					<RadioCard
						key={ server.id }
						name="qs-server-pick"
						value={ String( server.id ) }
						selected={ ! createIntent && selectedId === server.id }
						onSelect={ () => handleSelectExisting( server.id ) }
						title={ server.name }
						subtitle={ <code>{ server.route_full }</code> }
						badge={
							! server.enabled ? (
								<span className="qs-card__badge qs-card__badge--inactive">
									{ __( 'Inactive', 'acrossai-mcp-manager' ) }
								</span>
							) : null
						}
					/>
				) ) }

				<RadioCard
					name="qs-server-pick"
					value={ CREATE_TILE_VALUE }
					selected={ createIntent }
					onSelect={ handleSelectCreate }
					title={ __( '+ Create a new server', 'acrossai-mcp-manager' ) }
					subtitle={ __(
						'Set up a brand-new MCP server for this wizard.',
						'acrossai-mcp-manager'
					) }
				/>
			</div>
		</div>
	);
};

export default Step1_ServerPick;
