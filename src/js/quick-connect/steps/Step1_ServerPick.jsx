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

// Kept in sync with DefaultServerSeeder::ACROSSAI_SLUG / ::SLUG on the PHP
// side, most-preferred first. These are the plugin-managed seeded rows; the
// first one is also the "Recommended" server and is pinned to the top of the
// picker (mirroring MCPServerListTable::prepare_items()). If none of them are
// present (dev environment, manual delete) we fall back to whatever server
// sits at index 0 in the DB-ordered list.
const PREFERRED_SERVER_SLUGS = [
	'acrossai-mcp-server',
	'mcp-adapter-default-server',
];

// The single slug surfaced as "Recommended" — mirrors
// ProtectedServers::is_recommended() on the PHP side.
const RECOMMENDED_SERVER_SLUG = PREFERRED_SERVER_SLUGS[ 0 ];

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

	// First-load auto-select — pick the Recommended seeded server (falling
	// back through PREFERRED_SERVER_SLUGS, then index 0) when the user has no
	// prior pick AND no explicit create intent. Runs once per mount; if the
	// user actively picks a different card the condition stops matching so
	// this effect stops re-firing.
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
			PREFERRED_SERVER_SLUGS.reduce(
				( found, slug ) =>
					found || state.servers.find( ( s ) => s.slug === slug ),
				null
			) || state.servers[ 0 ];
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

	// Recommended server pinned first; everything else keeps the order the
	// REST state returned (which is the list table's id ASC).
	const servers = useMemo( () => {
		const all = state.servers || [];
		return [
			...all.filter( ( s ) => s.slug === RECOMMENDED_SERVER_SLUG ),
			...all.filter( ( s ) => s.slug !== RECOMMENDED_SERVER_SLUG ),
		];
	}, [ state.servers ] );

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
							<>
								{ server.slug ===
								RECOMMENDED_SERVER_SLUG ? (
									<span className="qs-card__badge qs-card__badge--recommended">
										{ __(
											'Recommended',
											'acrossai-mcp-manager'
										) }
									</span>
								) : null }
								{ ! server.enabled ? (
									<span className="qs-card__badge qs-card__badge--inactive">
										{ __(
											'Inactive',
											'acrossai-mcp-manager'
										) }
									</span>
								) : null }
							</>
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
