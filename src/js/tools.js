/**
 * Feature 020 — Per-server Tool Selection React app.
 *
 * Hand-rolled two-column shuttle picker. **Optimistic-per-toggle**:
 * each Add / Remove click POSTs immediately to
 * `/acrossai-mcp-manager/v1/servers/{id}/tools` with the full new set;
 * on failure the local state rolls back and an error notice surfaces.
 * The Save changes / Cancel bar has been retired at operator request —
 * "once I add it, it's saved."
 *
 * Uses only `@wordpress/*` Tier 1 packages — no react-query, redux,
 * mobx, `@tanstack`, react-table, `@mui`, or styled-components. Enforced
 * by grep gate T053.
 *
 * @package
 */

import {
	createElement,
	createRoot,
	Fragment,
	useState,
	useEffect,
	useMemo,
} from '@wordpress/element';
import {
	Button,
	SearchControl,
	Spinner,
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- ConfirmDialog has no stable equivalent yet; pre-existing F020 usage.
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useSelect } from '@wordpress/data';

/**
 * The three MCP protocol slugs — mirror of PHP-side
 * `ToolPolicy::PROTOCOL_TOOLS` (single canonical PHP source; this JS mirror
 * kept in step by hand at build time). Used to identify protocol tools for
 * the Remove-with-warning ConfirmDialog and Reset payload construction.
 *
 * F025: no longer filters the left "All abilities" pool — protocol slugs are
 * first-class entries visible in both panes with the recommended-defaults
 * color treatment (see TYPE_STYLE.Built-in).
 */
const PROTOCOL_TOOL_SLUGS = [
	'mcp-adapter/discover-abilities',
	'mcp-adapter/get-ability-info',
	'mcp-adapter/execute-ability',
];

/**
 * The three MCP-adapter protocol tools that ship built-in to every server —
 * shown in the right column as always-added, non-removable. Metadata mirrors
 * the retired `ToolsTab::get_core_tools()` PHP method so the UI still
 * surfaces the "Discover Abilities" / "Get Ability Info" / "Execute Ability"
 * primitives without operators needing to know they're protocol plumbing.
 *
 * Labels + descriptions run through i18n at render time. `type = 'Built-in'`
 * uses a distinct badge color (not Tool/Prompt/Resource) so operators can
 * visually distinguish protocol tools from curated ones.
 */
const BUILTIN_ABILITIES = [
	{
		name: 'mcp-adapter/discover-abilities',
		getLabel: () => __( 'Discover Abilities', 'acrossai-mcp-manager' ),
		getDescription: () =>
			__(
				'Lists all publicly available WordPress abilities registered on this site. AI clients use this to discover what actions the server can perform.',
				'acrossai-mcp-manager',
			),
		type: 'Built-in',
	},
	{
		name: 'mcp-adapter/get-ability-info',
		getLabel: () => __( 'Get Ability Info', 'acrossai-mcp-manager' ),
		getDescription: () =>
			__(
				'Returns detailed information about a specific ability, including its input/output schema and description. Used by AI clients before executing an ability.',
				'acrossai-mcp-manager',
			),
		type: 'Built-in',
	},
	{
		name: 'mcp-adapter/execute-ability',
		getLabel: () => __( 'Execute Ability', 'acrossai-mcp-manager' ),
		getDescription: () =>
			__(
				'Executes a WordPress ability with the provided input parameters and returns the result. This is the primary tool used by AI clients to interact with WordPress.',
				'acrossai-mcp-manager',
			),
		type: 'Built-in',
	},
];

/**
 * Type badge palette — matches the mockup at tools-ui.zip → Tools Selection.dc.html:178-183.
 * `Built-in` is F020's addition for the three protocol tools shown always-added
 * in the right column — muted amber to visually distinguish from operator-curated
 * types.
 */
const TYPE_STYLE = {
	Tool: { bg: '#e5f0f8', fg: '#0a4b78' },
	Prompt: { bg: '#f3e8fd', fg: '#6b21a8' },
	Resource: { bg: '#e6f6ec', fg: '#0a6b3d' },
	'Built-in': { bg: '#fef7e0', fg: '#8a6d00' },
};

/**
 * Defensive filter boundary. Mirrors src/js/abilities.js:safeApplyFilters —
 * a broken third-party callback must NOT white-screen the mount.
 *
 * @param {string} hookName     Filter name.
 * @param {*}      defaultValue Value returned on callback throw.
 * @param {...*}   args         Extra args passed to applyFilters.
 * @return {*} Result of applyFilters, or defaultValue on throw.
 */
export function safeApplyFilters( hookName, defaultValue, ...args ) {
	try {
		return applyFilters( hookName, defaultValue, ...args );
	} catch ( e ) {
		// eslint-disable-next-line no-console
		console.error(
			`[acrossaiMcpTools] Filter "${ hookName }" callback threw:`,
			e,
		);
		return defaultValue;
	}
}

/**
 * Render a single ability row.
 *
 * @param {Object}    props
 * @param {Object}    props.ability     Ability metadata.
 * @param {string}    props.side        'available' | 'added' | 'builtin' — controls
 *                                      badge, background, and action-button behavior.
 * @param {?Function} props.onAction    Add/Remove handler (omit for `builtin`).
 * @param {?string}   props.actionLabel Button label (omit for `builtin`).
 * @param {?boolean}  props.busy        When true, the action button is disabled
 *                                      to prevent double-clicks during a POST.
 */
function AbilityRow( { ability, side, onAction, actionLabel, busy } ) {
	const decoration = safeApplyFilters(
		'acrossaiMcpManager.tools.row',
		{},
		ability,
		{ side },
	);
	// F025: protocol tools are first-class entries in either pane; they no
	// longer occupy their own "locked" side. Detect by slug so the visual
	// treatment ("Built-in" badge + #fef7e0 background) travels with the row
	// regardless of which pane it's in.
	const isProtocolTool = PROTOCOL_TOOL_SLUGS.includes( ability.name );
	const typeStyle = isProtocolTool
		? TYPE_STYLE[ 'Built-in' ]
		: TYPE_STYLE[ ability.type ] || { bg: '#f0f0f1', fg: '#50575e' };
	const rowClass = [ 'acrossai-mcp-tools-row', decoration.className ]
		.filter( Boolean )
		.join( ' ' );
	const showCheckmark = side === 'added';
	// F025: protocol rows keep the recommended-defaults tint in both panes.
	// Non-protocol added rows keep the F020 subtle blue; non-protocol available
	// rows keep the neutral white.
	let rowBg = '';
	if ( isProtocolTool ) {
		rowBg = '#fef7e0';
	} else if ( side === 'added' ) {
		rowBg = '#f9fcff';
	}
	const displayType = isProtocolTool ? 'Built-in' : ability.type;

	return createElement(
		'div',
		{
			className: rowClass,
			style: {
				display: 'flex',
				alignItems: 'flex-start',
				gap: '12px',
				padding: '13px 16px',
				borderBottom: '1px solid #f0f0f1',
				background: rowBg,
			},
		},
		showCheckmark
			? createElement(
				'span',
				{
					style: {
						flex: 'none',
						width: '22px',
						height: '22px',
						borderRadius: '50%',
						background: isProtocolTool ? '#fdefb2' : '#e6f6ec',
						color: isProtocolTool ? '#8a6d00' : '#0a6b3d',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						fontSize: '13px',
						fontWeight: 700,
						marginTop: '1px',
					},
				},
				'✓',
			)
			: null,
		decoration.prepend || null,
		createElement(
			'div',
			{ style: { flex: 1, minWidth: 0 } },
			createElement(
				'div',
				{
					style: {
						display: 'flex',
						alignItems: 'center',
						gap: '9px',
						flexWrap: 'wrap',
					},
				},
				createElement(
					'span',
					{
						style: {
							fontSize: '14px',
							fontWeight: 700,
							color: '#1d2327',
						},
					},
					ability.label || ability.name,
				),
				displayType
					? createElement(
						'span',
						{
							style: {
								display: 'inline-block',
								fontSize: '11.5px',
								fontWeight: 600,
								borderRadius: '3px',
								padding: '2px 8px',
								background: typeStyle.bg,
								color: typeStyle.fg,
							},
						},
						displayType,
					)
					: null,
			),
			createElement(
				'div',
				{ style: { marginTop: '5px' } },
				createElement(
					'code',
					{
						style: {
							fontSize: '12px',
							background: '#f0f0f1',
							color: '#2c3338',
							padding: '2px 7px',
							borderRadius: '3px',
							wordBreak: 'break-all',
						},
					},
					ability.name,
				),
			),
			ability.description
				? createElement(
					'div',
					{
						style: {
							fontSize: '12.5px',
							color: '#646970',
							lineHeight: 1.55,
							marginTop: '7px',
						},
					},
					ability.description,
				)
				: null,
			decoration.append || null,
		),
		createElement( Button, {
			variant: side === 'added' ? 'secondary' : 'primary',
			isSmall: true,
			disabled: !! busy,
			onClick: () => onAction( ability.name ),
			children: actionLabel,
		} ),
	);
}

/**
 * Main React component — the shuttle picker.
 * @param {Object} props
 * @param {number} props.serverId
 */
function ToolsApp( { serverId } ) {
	const config = window.acrossaiMcpTools || {};
	// Single source of truth — the operator-curated tool set as the server
	// has it. Optimistic-per-toggle: each Add/Remove POSTs immediately.
	const [ added, setAdded ] = useState( new Set() );
	const [ search, setSearch ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ abilitiesFromRest, setAbilitiesFromRest ] = useState( [] );
	// F025 US2: confirmation-dialog gating state for protocol-slug removal.
	// When set, holds the slug pending confirmation; ConfirmDialog is open.
	const [ pendingProtocolRemove, setPendingProtocolRemove ] = useState( null );
	// F025 US3: same gating for the destructive Reset action.
	const [ pendingReset, setPendingReset ] = useState( false );

	// B22 — Prefer the @wordpress/abilities data store when it exists (v0.x
	// packages aren't in @wordpress/scripts externals map, so we look up by
	// string key at runtime, not by build-time import).
	const abilitiesFromStore = useSelect( ( select ) => {
		const store = select( 'core/abilities' );
		if ( store && typeof store.getAbilities === 'function' ) {
			return store.getAbilities();
		}
		return null;
	}, [] );

	const abilities = useMemo( () => {
		const source =
			Array.isArray( abilitiesFromStore ) && abilitiesFromStore.length > 0
				? abilitiesFromStore
				: abilitiesFromRest;
		// F025: no filter on protocol slugs — they're first-class entries in the pool.
		return source;
	}, [ abilitiesFromStore, abilitiesFromRest ] );

	// Initial mount: GET /tools?include_abilities=1
	useEffect( () => {
		const path = `/${ config.namespace }/servers/${ serverId }/tools?include_abilities=1`;
		apiFetch( { path } )
			.then( ( response ) => {
				setAdded( new Set( response.tools || [] ) );
				if ( Array.isArray( response.abilities ) ) {
					setAbilitiesFromRest( response.abilities );
				}
			} )
			.catch( ( err ) => {
				setError( err.message || __( 'Failed to load tools.', 'acrossai-mcp-manager' ) );
			} )
			.finally( () => {
				setLoading( false );
			} );
	}, [ config.namespace, serverId ] );

	const visibleAvailable = useMemo( () => {
		const q = search.trim().toLowerCase();
		return abilities.filter( ( a ) => {
			if ( added.has( a.name ) ) {
				return false;
			}
			if ( ! q ) {
				return true;
			}
			return (
				a.name.toLowerCase().includes( q ) ||
				( a.label || '' ).toLowerCase().includes( q ) ||
				( a.description || '' ).toLowerCase().includes( q ) ||
				( a.category || '' ).toLowerCase().includes( q )
			);
		} );
	}, [ abilities, added, search ] );

	const addedRows = useMemo( () => {
		const byName = Object.fromEntries( abilities.map( ( a ) => [ a.name, a ] ) );
		// F025: build a BUILTIN_ABILITIES metadata fallback for the three
		// protocol slugs — the vendor registers them via wp_register_ability
		// so they should appear in the abilities pool, but the fallback keeps
		// the UI correct if the pool is briefly empty (e.g., during initial
		// load or if the abilities data store is unavailable).
		const builtinByName = Object.fromEntries(
			BUILTIN_ABILITIES.map( ( b ) => [
				b.name,
				{
					name: b.name,
					label: b.getLabel(),
					description: b.getDescription(),
					type: b.type,
					category: '',
				},
			] ),
		);
		// Order: protocol slugs first (in PROTOCOL_TOOL_SLUGS order — matches
		// PHP-side ToolPolicy::COLUMN_MAP iteration), then curated in
		// insertion order returned by the server.
		const protocolAdded = PROTOCOL_TOOL_SLUGS.filter( ( slug ) =>
			added.has( slug ),
		);
		const curatedAdded = Array.from( added ).filter(
			( slug ) => ! PROTOCOL_TOOL_SLUGS.includes( slug ),
		);
		return [ ...protocolAdded, ...curatedAdded ].map(
			( name ) =>
				byName[ name ] ||
				builtinByName[ name ] || {
					name,
					label: name,
					description: __(
						'(ability no longer registered)',
						'acrossai-mcp-manager',
					),
					type: '',
					category: '',
				},
		);
	}, [ abilities, added ] );

	/**
	 * Persist the given tool set to the server. Optimistically updates local
	 * state before the POST; on error, rolls back to the previous state and
	 * surfaces the error to the operator.
	 *
	 * @param {Set<string>} nextSet The desired full tool set after this action.
	 * @param {Set<string>} prevSet The prior tool set — used for rollback on failure.
	 */
	const persistSet = ( nextSet, prevSet ) => {
		setAdded( nextSet ); // Optimistic — UI reflects the change immediately.
		setSaving( true );
		setError( null );
		const path = `/${ config.namespace }/servers/${ serverId }/tools`;
		apiFetch( {
			path,
			method: 'POST',
			data: { tools: Array.from( nextSet ) },
		} )
			.then( ( response ) => {
				// Server truth — reconcile against what actually persisted.
				setAdded( new Set( response.tools || [] ) );
			} )
			.catch( ( err ) => {
				// Rollback the optimistic update — the server rejected the
				// change (403 / 400 / 500) or the network failed.
				setAdded( prevSet );
				setError( err.message || __( 'Save failed.', 'acrossai-mcp-manager' ) );
			} )
			.finally( () => {
				setSaving( false );
			} );
	};

	const addAbility = ( name ) => {
		const prev = new Set( added );
		const next = new Set( added );
		next.add( name );
		persistSet( next, prev );
	};
	// F025 US2: internal helper that actually applies a removal. Called
	// directly for non-protocol slugs; gated behind the ConfirmDialog for
	// protocol slugs.
	const applyRemove = ( name ) => {
		const prev = new Set( added );
		const next = new Set( added );
		next.delete( name );
		persistSet( next, prev );
	};
	const removeAbility = ( name ) => {
		if ( PROTOCOL_TOOL_SLUGS.includes( name ) ) {
			// Gate through the ConfirmDialog (FR-003 / SEC-025-INFO-1).
			setPendingProtocolRemove( name );
			return;
		}
		// FR-006: non-protocol removals bypass the dialog.
		applyRemove( name );
	};
	// F025 US3: Reset now sets the tool set to exactly the three protocol
	// slugs — the backend's ToolPolicy::split_payload flips all three columns
	// to 1 and calls replace_set with an empty curated array, dropping every
	// non-protocol row atomically.
	const applyReset = () => {
		const prev = new Set( added );
		persistSet( new Set( PROTOCOL_TOOL_SLUGS ), prev );
	};
	const openResetDialog = () => setPendingReset( true );

	if ( loading ) {
		return createElement(
			'div',
			{ style: { padding: '40px', textAlign: 'center' } },
			createElement( Spinner ),
		);
	}

	const totalPool = abilities.length;

	return createElement(
		Fragment,
		null,
		error
			? createElement(
				Notice,
				{ status: 'error', onRemove: () => setError( null ) },
				error,
			)
			: null,
		createElement(
			'div',
			{
				style: {
					display: 'flex',
					alignItems: 'baseline',
					justifyContent: 'space-between',
					flexWrap: 'wrap',
					gap: '10px',
					marginBottom: '6px',
				},
			},
			createElement(
				'span',
				null,
				sprintf(
					/* translators: 1: added count, 2: total available count */
					__(
						'%1$d of %2$d abilities added as tools',
						'acrossai-mcp-manager',
					),
					added.size,
					totalPool,
				),
			),
		),
		createElement(
			'div',
			{
				style: {
					display: 'grid',
					gridTemplateColumns: '1fr 1fr',
					gap: '20px',
					marginTop: '22px',
					alignItems: 'start',
				},
			},
			// LEFT column: available abilities.
			createElement(
				'div',
				{
					style: {
						border: '1px solid #c3c4c7',
						borderRadius: '8px',
						overflow: 'hidden',
						display: 'flex',
						flexDirection: 'column',
					},
				},
				createElement(
					'div',
					{
						style: {
							padding: '13px 16px',
							background: '#f6f7f7',
							borderBottom: '1px solid #c3c4c7',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'space-between',
							gap: '10px',
						},
					},
					createElement(
						'div',
						null,
						createElement(
							'span',
							{ style: { fontSize: '14px', fontWeight: 700 } },
							__( 'All abilities', 'acrossai-mcp-manager' ),
						),
						' ',
						createElement(
							'span',
							{ style: { fontSize: '12.5px', color: '#646970' } },
							sprintf(
								/* translators: %d: available ability count */
								__( '%d available', 'acrossai-mcp-manager' ),
								visibleAvailable.length,
							),
						),
					),
				),
				createElement(
					'div',
					{ style: { padding: '12px 14px', borderBottom: '1px solid #e0e0e2' } },
					createElement( SearchControl, {
						value: search,
						onChange: setSearch,
						placeholder: __( 'Search abilities…', 'acrossai-mcp-manager' ),
					} ),
				),
				createElement(
					'div',
					{ style: { maxHeight: '560px', overflow: 'auto' } },
					visibleAvailable.length === 0
						? createElement(
							'div',
							{ style: { padding: '40px 24px', textAlign: 'center', color: '#646970' } },
							search.trim()
								? __( 'No abilities match your search.', 'acrossai-mcp-manager' )
								: __( 'Every ability has been added as a tool.', 'acrossai-mcp-manager' ),
						)
						: visibleAvailable.map( ( a ) =>
							createElement( AbilityRow, {
								key: a.name,
								ability: a,
								side: 'available',
								onAction: addAbility,
								actionLabel: __( '+ Add', 'acrossai-mcp-manager' ),
								busy: saving,
							} ),
						),
				),
			),
			// RIGHT column: added tools.
			createElement(
				'div',
				{
					style: {
						border: '1px solid #c3c4c7',
						borderRadius: '8px',
						overflow: 'hidden',
						display: 'flex',
						flexDirection: 'column',
					},
				},
				createElement(
					'div',
					{
						style: {
							padding: '13px 16px',
							background: '#f0f6fc',
							borderBottom: '1px solid #c3c4c7',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'space-between',
							gap: '10px',
						},
					},
					createElement(
						'div',
						null,
						createElement(
							'span',
							{ style: { fontSize: '14px', fontWeight: 700 } },
							__( 'Added as tools', 'acrossai-mcp-manager' ),
						),
						' ',
						createElement(
							'span',
							{
								style: {
									display: 'inline-flex',
									alignItems: 'center',
									justifyContent: 'center',
									minWidth: '20px',
									height: '20px',
									padding: '0 6px',
									borderRadius: '10px',
									background: '#2271b1',
									color: '#fff',
									fontSize: '12px',
									fontWeight: 700,
								},
								title: __(
									'Composed union of enabled built-in defaults and curated abilities.',
									'acrossai-mcp-manager',
								),
							},
							String( added.size ),
						),
					),
					createElement( Button, {
						variant: 'secondary',
						isSmall: true,
						onClick: openResetDialog,
						// F025: Reset is always meaningful — even when the pane
						// looks default, it clears any invisible curated rows
						// and re-affirms all three protocol columns as 1.
						disabled: saving,
						children: __( 'Reset', 'acrossai-mcp-manager' ),
					} ),
				),
				createElement(
					'div',
					{ style: { maxHeight: '632px', overflow: 'auto', flex: 1 } },
					// F025: single unified list — protocol slugs (with #fef7e0
					// background applied via AbilityRow's isProtocolTool
					// detection) render first, curated slugs after. The former
					// separate "Always available" section is gone; operators
					// can now Remove protocol slugs via the confirmation dialog.
					addedRows.length === 0
						? createElement(
							// FR-017 empty-state warning banner: rendered
							// INSIDE the pane so operators immediately see
							// why the pane is empty and how to recover.
							'div',
							{
								style: {
									padding: '20px 24px',
									background: '#fcf9f0',
									borderLeft: '4px solid #dba617',
								},
							},
							createElement(
								'div',
								{
									style: {
										fontSize: '14px',
										fontWeight: 700,
										color: '#3c434a',
										marginBottom: '6px',
									},
								},
								__(
									'This server has no tools',
									'acrossai-mcp-manager',
								),
							),
							createElement(
								'div',
								{
									style: {
										fontSize: '13px',
										color: '#3c434a',
										lineHeight: 1.55,
										marginBottom: '12px',
									},
								},
								__(
									"This server has no tools. AI clients can't discover or execute abilities. Click Reset to restore defaults.",
									'acrossai-mcp-manager',
								),
							),
							createElement( Button, {
								variant: 'primary',
								isSmall: true,
								onClick: openResetDialog,
								disabled: saving,
								children: __( 'Reset to defaults', 'acrossai-mcp-manager' ),
							} ),
						)
						: addedRows.map( ( a ) =>
							createElement( AbilityRow, {
								key: a.name,
								ability: a,
								side: 'added',
								onAction: removeAbility,
								actionLabel: __( 'Remove', 'acrossai-mcp-manager' ),
								busy: saving,
							} ),
						),
				),
			),
		),
		// F025 US2 — ConfirmDialog for protocol-tool removal (FR-003).
		pendingProtocolRemove
			? createElement(
				ConfirmDialog,
				{
					isOpen: true,
					onConfirm: () => {
						const slug = pendingProtocolRemove;
						setPendingProtocolRemove( null );
						applyRemove( slug );
					},
					onCancel: () => setPendingProtocolRemove( null ),
					confirmButtonText: __(
						'Remove anyway',
						'acrossai-mcp-manager',
					),
					cancelButtonText: __(
						'Cancel',
						'acrossai-mcp-manager',
					),
				},
				__(
					'This tool is required by AI clients to discover and execute WordPress abilities on this server. Removing it may prevent connected AI clients from working correctly. Are you sure you want to remove it?',
					'acrossai-mcp-manager',
				),
			)
			: null,
		// F025 US3 — ConfirmDialog for Reset (destructive: wipes curated picks).
		pendingReset
			? createElement(
				ConfirmDialog,
				{
					isOpen: true,
					onConfirm: () => {
						setPendingReset( false );
						applyReset();
					},
					onCancel: () => setPendingReset( false ),
					confirmButtonText: __(
						'Reset to defaults',
						'acrossai-mcp-manager',
					),
					cancelButtonText: __(
						'Cancel',
						'acrossai-mcp-manager',
					),
				},
				__(
					'Reset the tools for this server to only the three built-in defaults? All curated picks will be removed.',
					'acrossai-mcp-manager',
				),
			)
			: null,
		// mcp-adapter info banner (always visible below the columns).
		createElement(
			'div',
			{
				style: {
					display: 'flex',
					alignItems: 'flex-start',
					gap: '12px',
					background: '#fff',
					border: '1px solid #c3c4c7',
					borderLeft: '4px solid #2271b1',
					padding: '13px 16px',
					marginTop: '20px',
				},
			},
			createElement(
				'div',
				{ style: { fontSize: '13.5px', color: '#3c434a', lineHeight: 1.55 } },
				__(
					'Every ability added here is exposed as an MCP tool through the wordpress/mcp-adapter package. AI clients call these tools to run the underlying WordPress abilities registered in the Abilities tab.',
					'acrossai-mcp-manager',
				),
			),
		),
		// Saving indicator — subtle spinner shown while a POST is in-flight,
		// replacing the retired Save changes / Cancel bar. Each Add / Remove /
		// Reset click now commits automatically.
		saving
			? createElement(
				'div',
				{
					style: {
						display: 'flex',
						alignItems: 'center',
						gap: '10px',
						marginTop: '24px',
						paddingTop: '20px',
						borderTop: '1px solid #e0e0e2',
						fontSize: '13px',
						color: '#646970',
					},
				},
				createElement( Spinner ),
				__( 'Saving…', 'acrossai-mcp-manager' ),
			)
			: null,
	);
}

// Mount on DOMContentLoaded (or immediately if the script is already deferred to end of body).
function mount() {
	const root = document.getElementById( 'acrossai-mcp-tools-root' );
	if ( ! root ) {
		return; // Silent no-op — the enqueue guard failed and we're on the wrong screen.
	}
	const config = window.acrossaiMcpTools || {};

	// Wire the plugin-scoped nonce onto apiFetch. Without this, POST /tools
	// silently 403s (WordPress admin's default wpApiSettings.nonce is not
	// scoped to our route). Bug fix: user reported "add + reload = row
	// removed" because Save was returning 403 while the UI mistook the failure
	// for a successful commit. Mirrors src/js/abilities.js:95.
	if ( config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}
	// Root the API URL to the localized restApiRoot so path-only apiFetch
	// calls resolve to the correct site (relevant for multisite installs and
	// non-default REST prefixes). Value is already `untrailingslashit`-clean.
	if ( config.restApiRoot ) {
		apiFetch.use( apiFetch.createRootURLMiddleware( config.restApiRoot + '/' ) );
	}

	const serverId = parseInt( root.getAttribute( 'data-server-id' ) || '0', 10 );
	const serverSlug = root.getAttribute( 'data-server-slug' ) || '';
	root.innerHTML = ''; // Clear the "Loading tools…" placeholder.
	createRoot( root ).render(
		createElement( ToolsApp, { serverId, serverSlug } ),
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
