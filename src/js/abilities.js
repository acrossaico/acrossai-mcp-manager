/**
 * Feature 017 — Per-server Ability Selection React entry.
 *
 * Mounts a `@wordpress/dataviews`-driven table on the per-server Abilities
 * tab (`?page=acrossai_mcp_manager&action=edit&server=<id>&tab=abilities`).
 * The tab lets admins toggle exposure of each registered WordPress Ability
 * per MCP server; effective exposure falls back to `meta[mcp][public]` when
 * no per-server row exists (FR-007).
 *
 * Extensibility surface (FR-026..029):
 *   - JS filter `acrossaiMcpManager.abilities.fields` — add columns
 *   - JS filter `acrossaiMcpManager.abilities.actions` — add bulk actions
 *   - JS filter `acrossaiMcpManager.abilities.row` — decorate rows
 *
 * All three filters are wrapped in `safeApplyFilters` so a throwing
 * companion-plugin callback never white-screens the tab. Built-in
 * `fields`/`actions`/`row` keys are re-asserted after each filter fires so
 * extensions cannot remove or overwrite core columns.
 *
 * Compiled to `build/js/abilities.js` by webpack.config.js. Enqueued only
 * on the Abilities tab by `admin/Main.php::maybe_enqueue_abilities_app()`.
 *
 * @since 0.1.0
 * @package
 */

import {
	createRoot,
	createElement,
	useState,
	useEffect,
	useMemo,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import {
	ToggleControl,
	Notice,
	Spinner,
	SelectControl,
	SearchControl,
	CheckboxControl,
	Button,
	Modal,
} from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { useSelect } from '@wordpress/data';

// SCSS is bundled with this entry — @wordpress/scripts extracts it to
// `build/js/abilities.css`, which `admin/Main.php::maybe_enqueue_abilities_app()`
// enqueues alongside the JS bundle.
import '../scss/abilities.scss';

/*
 * NB: `@wordpress/abilities` (v0.16.0 as of 2026-07) is very new and
 * @wordpress/scripts may not know how to externalize it against a
 * `wp-abilities` handle. Instead of importing `store as abilitiesStore`
 * at build time, we look up the store at runtime via `wp.data.select()`
 * with the well-known WordPress convention key `core/abilities`. When
 * the store is unavailable (Abilities API JS package not loaded), we
 * fall back to a REST call that returns the full ability list from
 * PHP `wp_get_abilities()`.
 */
const ABILITIES_STORE_KEY = 'core/abilities';

/**
 * Slugs excluded from the operator-facing table. These are the MCP
 * adapter's own protocol-plumbing tools — every MCP server has to expose
 * them to be spec-compliant, so making them operator-selectable would
 * either produce a no-op toggle (the adapter re-exposes them anyway) or
 * silently break the protocol for connected clients.
 */
const EXCLUDED_SLUGS = new Set( [
	'mcp-adapter/discover-abilities',
	'mcp-adapter/get-ability-info',
	'mcp-adapter/execute-ability',
] );

( function() {
	const mount = document.getElementById( 'acrossai-mcp-abilities-root' );
	if ( ! mount ) {
		return;
	}

	const config = window.acrossaiMcpAbilities || {};
	if ( ! config.serverId || ! config.namespace ) {
		mount.textContent = __(
			'Abilities app cannot boot — missing serverId or namespace.',
			'acrossai-mcp-manager',
		);
		return;
	}

	if ( config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}

	/**
	 * Defensive `applyFilters` boundary (FR-029) — never lets a broken
	 * third-party filter callback white-screen the tab.
	 *
	 * @since 0.1.0 @experimental May change without notice before 1.0.0
	 * @param {string} name  Filter name.
	 * @param {*}      value Input value (fields array / actions array / row object).
	 * @param {Object} ctx   Filter context ({ serverId, serverSlug }).
	 * @return {*} Filter output on success; input on failure or invalid return.
	 */
	function safeApplyFilters( name, value, ctx ) {
		try {
			const out = applyFilters( name, value, ctx );
			if ( name.endsWith( '.fields' ) || name.endsWith( '.actions' ) ) {
				return Array.isArray( out ) ? out : value;
			}
			return out && typeof out === 'object' ? out : value;
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error(
				`[acrossai-mcp-manager] filter "${ name }" threw:`,
				err,
			);
			return value;
		}
	}

	/**
	 * Custom footer bar rendered below DataViews.
	 *
	 * Layout: [Select all checkbox] · [Prev / page-of-N / Next] · [N of M Items]
	 *
	 * @param {Object}   props                Footer props (view, setView, shownData,
	 *                                        selection, setSelection, totalItems, paginationInfo).
	 * @param {Object}   props.view
	 * @param {Function} props.setView
	 * @param {Array}    props.shownData
	 * @param {Array}    props.selection
	 * @param {Function} props.setSelection
	 * @param {number}   props.totalItems
	 * @param {Object}   props.paginationInfo
	 * @return {Object} React element.
	 */
	function AbilitiesFooter( {
		view: fView,
		setView: setFView,
		shownData,
		selection: fSelection,
		setSelection: setFSelection,
		totalItems,
		paginationInfo: fPagination,
	} ) {
		const perPage = fView.perPage || 50;
		const page = fView.page || 1;
		const totalPages =
			( fPagination && fPagination.totalPages ) ||
			Math.max( 1, Math.ceil( totalItems / perPage ) );

		const allSlugs = shownData.map( ( it ) => it.slug );
		const allSelected =
			allSlugs.length > 0 &&
			allSlugs.every( ( s ) => fSelection.indexOf( s ) !== -1 );

		function toggleAll( checked ) {
			if ( checked ) {
				// Union with existing selection so cross-page selections stick.
				const next = Array.from(
					new Set( [ ...fSelection, ...allSlugs ] ),
				);
				setFSelection( next );
			} else {
				setFSelection(
					fSelection.filter( ( s ) => allSlugs.indexOf( s ) === -1 ),
				);
			}
		}

		function goPrev() {
			if ( page > 1 ) {
				setFView( ( v ) => ( { ...v, page: page - 1 } ) );
			}
		}
		function goNext() {
			if ( page < totalPages ) {
				setFView( ( v ) => ( { ...v, page: page + 1 } ) );
			}
		}

		return createElement(
			'div',
			{ className: 'acrossai-mcp-abilities-footer' },
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-footer__left' },
				createElement( CheckboxControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Select all', 'acrossai-mcp-manager' ),
					checked: allSelected,
					onChange: toggleAll,
				} ),
			),
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-footer__center' },
				createElement(
					Button,
					{
						variant: 'tertiary',
						size: 'small',
						disabled: page <= 1,
						onClick: goPrev,
						'aria-label': __(
							'Previous page',
							'acrossai-mcp-manager',
						),
					},
					'‹',
				),
				createElement(
					'span',
					{ className: 'acrossai-mcp-abilities-footer__page' },
					sprintf(
						/* translators: 1: current page, 2: total pages */
						__( 'Page %1$d of %2$d', 'acrossai-mcp-manager' ),
						page,
						totalPages,
					),
				),
				createElement(
					Button,
					{
						variant: 'tertiary',
						size: 'small',
						disabled: page >= totalPages,
						onClick: goNext,
						'aria-label': __(
							'Next page',
							'acrossai-mcp-manager',
						),
					},
					'›',
				),
			),
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-footer__right' },
				sprintf(
					/* translators: 1: rows on current page, 2: total items */
					__(
						'%1$d of %2$d Items',
						'acrossai-mcp-manager',
					),
					shownData.length,
					totalItems,
				),
			),
		);
	}

	function App() {
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( null );
		// F082 — server-level default policy ('per-ability' | 'expose' | 'hide').
		const [ policy, setPolicy ] = useState( 'per-ability' );
		// F082 — server-computed effective exposure map from the augmented GET
		// response. Keyed by slug. Shape: { [slug]: { is_exposed: bool, has_override: bool } }.
		// Populated on every fetch; source of truth for the client (spec FR-016).
		const [ serverExposure, setServerExposure ] = useState( {} );
		// F082 — confirm-modal state for Enable All / Disable All (spec Clarifications Q3).
		// null when closed; { policy: 'expose'|'hide' } when open pending confirmation.
		const [ confirmModal, setConfirmModal ] = useState( null );
		// Selection state — driven by both DataViews' built-in row checkbox
		// and by our custom footer's "Select all" checkbox.
		const [ selection, setSelection ] = useState( [] );
		// Custom exposure filter — '' = show all, 'exposed' = only enabled,
		// 'hidden' = only disabled. Managed outside `view.filters` because
		// DataViews' filter system trips on boolean field values.
		const [ exposureFilter, setExposureFilter ] = useState( '' );
		const [ view, setView ] = useState( {
			type: 'table',
			search: '',
			filters: [],
			// `view.fields` lists the ids of columns visible in the table.
			// DataViews v6+ requires this — without it, only actions render.
			// Built-in ids match the useMemo `builtinFields` below.
			fields: [
				'slug',
				'label',
				'type',
				'category',
				'description',
				'is_exposed',
			],
			sort: { field: 'slug', direction: 'asc' },
			perPage: 50,
			page: 1,
			layout: {},
		} );

		const path = `/${ config.namespace }/servers/${ config.serverId }/abilities`;

		const filterCtx = useMemo(
			() => ( {
				serverId: config.serverId,
				serverSlug: config.serverSlug,
			} ),
			[],
		);

		// Ability list from the @wordpress/abilities data store — the
		// WordPress-canonical source of registered abilities on the client.
		// See https://developer.wordpress.org/block-editor/reference-guides/packages/packages-abilities/
		//
		// String-based store lookup (not import) so a build-time missing/new
		// package doesn't hard-break the bundle. `abilitiesFromStore === null`
		// signals "store not registered at runtime — fall back to REST".
		const abilitiesFromStore = useSelect(
			( select ) => {
				const store = select( ABILITIES_STORE_KEY );
				if ( ! store || typeof store.getAbilities !== 'function' ) {
					return null;
				}
				return store.getAbilities() || [];
			},
			[],
		);

		// REST fallback list — populated only when the client store is absent.
		const [ abilitiesFromRest, setAbilitiesFromRest ] = useState( null );

		// Apply one augmented GET response to component state. Single source
		// for the response→state mapping shared by the initial fetch, the
		// post-save reconcile, and the policy-modal flow. Deliberately reads
		// no changing state — it is reachable from first-render closures
		// inside `builtinFields` (empty dep array below).
		function applyServerResponse( res ) {
			// F082 — capture server-computed effective exposure per ability.
			const exposureMap = {};
			const augmented = ( res && Array.isArray( res.abilities ) ) ? res.abilities : [];
			augmented.forEach( ( a ) => {
				exposureMap[ a.name ] = {
					is_exposed: !! a.is_exposed,
					has_override: !! a.has_override,
				};
			} );
			setServerExposure( exposureMap );

			// F082 — capture server-level policy.
			if ( res && typeof res.abilities_default_policy === 'string' ) {
				setPolicy( res.abilities_default_policy );
			}

			// Backfill client-side ability metadata from the REST response.
			// Unconditional: the `abilities` derivation below prefers the data
			// store when present, so this is inert on store-backed clients —
			// and skipping the store check keeps this function free of
			// changing-state reads.
			if ( augmented.length > 0 ) {
				setAbilitiesFromRest( augmented );
			}
		}

		// Re-pull server truth. Initial load shows the Spinner; post-save
		// refreshes MUST NOT — setLoading(true) unmounts the whole table,
		// dropping selection, scroll position, and focus.
		function refreshFromServer( { withSpinner = false } = {} ) {
			if ( withSpinner ) {
				setLoading( true );
			}
			return apiFetch( { path: path + '?include_abilities=1' } )
				.then( ( res ) => {
					applyServerResponse( res );
					setError( null );
				} )
				.catch( ( e ) => setError( e && e.message ? e.message : String( e ) ) )
				.finally( () => {
					if ( withSpinner ) {
						setLoading( false );
					}
				} );
		}

		// F082 — always request the server-augmented response (server-computed
		// `is_exposed` per ability + `has_override` per ability + top-level
		// `abilities_default_policy`). Even when the `@wordpress/abilities`
		// data store is available, we still fetch the augmented response
		// because the store doesn't know per-server policy state.
		useEffect( () => {
			refreshFromServer( { withSpinner: true } );
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [ path ] );

		// Effective ability list — store preferred, REST fallback second.
		const abilities = useMemo(
			() =>
				abilitiesFromStore !== null
					? abilitiesFromStore
					: abilitiesFromRest || [],
			[ abilitiesFromStore, abilitiesFromRest ],
		);

		// F082 — server-truth for is_exposed + has_override. The pre-F082
		// client-derived exposure logic is retired per SEC-001 and spec
		// FR-016 — the client MUST trust the server-computed value from
		// ExposureResolver::resolve_effective() so the tab shows the correct
		// set on `policy='expose'` and `policy='hide'` servers (where the
		// row-only view would lie).
		//
		// During the initial-fetch transient the serverExposure map is empty;
		// items default to `is_exposed=false` until the augmented GET response
		// arrives. The surrounding `loading` state (Spinner) handles this UX.
		// EXCLUDED_SLUGS drops the MCP adapter's protocol-plumbing tools.
		const items = useMemo( () => {
			return abilities
				.filter( ( a ) => ! EXCLUDED_SLUGS.has( a.name ) )
				.map( ( a ) => {
					const meta = a.meta || {};
					const mcpMeta = meta.mcp || {};
					const serverExp = serverExposure[ a.name ];
					// F082 — server-truth ONLY. No client-side derivation.
					const isExposed = serverExp ? serverExp.is_exposed : false;
					const hasOverride = serverExp ? serverExp.has_override : false;
					return {
						slug: a.name,
						label: a.label || a.name,
						type: mcpMeta.type || 'tool',
						category: a.category || '',
						description: a.description || '',
						is_exposed: isExposed,
						has_override: hasOverride,
					};
				} );
		}, [ abilities, serverExposure ] );

		function saveMany( selectedItems, isExposed ) {
			const slugs = selectedItems.map( ( it ) => it.slug );
			// Optimistic flip of the server-truth map `items` renders from, so
			// the toggle moves instantly; the silent refresh below reconciles.
			setServerExposure( ( current ) => {
				const next = { ...current };
				slugs.forEach( ( slug ) => {
					next[ slug ] = { is_exposed: isExposed, has_override: true };
				} );
				return next;
			} );
			return apiFetch( {
				path,
				method: 'POST',
				data: {
					abilities: slugs.map( ( slug ) => ( {
						slug,
						is_exposed: isExposed,
					} ) ),
				},
			} )
				// The POST response only carries the override rows, not the
				// full effective state — re-pull server truth, no Spinner.
				.then( () => refreshFromServer() )
				.catch( ( e ) => {
					setError( e && e.message ? e.message : String( e ) );
					// Roll the optimistic flip back to server truth.
					return refreshFromServer();
				} );
		}

		function saveOne( slug, isExposed ) {
			return saveMany( [ { slug } ], isExposed );
		}

		// Row-level decoration hook — extensions may add extra keys their
		// column `render` callbacks can read.
		const decoratedItems = useMemo(
			() =>
				items.map( ( item ) =>
					safeApplyFilters(
						'acrossaiMcpManager.abilities.row',
						item,
						filterCtx,
					),
				),
			[ items, filterCtx ],
		);

		const builtinFields = useMemo(
			() => [
				{
					id: 'slug',
					label: __( 'Ability Name', 'acrossai-mcp-manager' ),
					enableGlobalSearch: true,
					render: ( { item } ) =>
						createElement( 'code', null, item.slug ),
				},
				{
					id: 'label',
					label: __( 'Label', 'acrossai-mcp-manager' ),
					enableGlobalSearch: true,
				},
				{
					id: 'type',
					label: __( 'Type', 'acrossai-mcp-manager' ),
					elements: [
						{
							value: 'tool',
							label: __( 'Tool', 'acrossai-mcp-manager' ),
						},
						{
							value: 'prompt',
							label: __( 'Prompt', 'acrossai-mcp-manager' ),
						},
						{
							value: 'resource',
							label: __( 'Resource', 'acrossai-mcp-manager' ),
						},
					],
					// `isPrimary: true` surfaces the filter dropdown inline
					// next to the search input instead of hiding it behind
					// the filter icon menu.
					filterBy: { operators: [ 'is' ] },
					render: ( { item } ) => {
						const value = ( item.type || 'tool' ).toLowerCase();
						const label =
							value.charAt( 0 ).toUpperCase() + value.slice( 1 );
						// Palette per ability kind — inline so DataViews'
						// cell-level styles can't fight us on specificity.
						// Matches the 1A mockup palette.
						const palette = {
							tool: { bg: '#dbeafe', fg: '#1e40af' },
							prompt: { bg: '#f3e8ff', fg: '#6b21a8' },
							resource: { bg: '#dcfce7', fg: '#166534' },
						};
						const c = palette[ value ] || palette.tool;
						return createElement(
							'span',
							{
								style: {
									display: 'inline-block',
									padding: '3px 10px',
									borderRadius: '4px',
									background: c.bg,
									color: c.fg,
									fontSize: '12px',
									fontWeight: 600,
									lineHeight: 1.4,
									letterSpacing: '0.01em',
								},
							},
							label,
						);
					},
				},
				{
					id: 'category',
					label: __( 'Category', 'acrossai-mcp-manager' ),
					getValue: ( { item } ) => item.category,
					filterBy: { operators: [ 'is' ] },
					render: ( { item } ) =>
						item.category
							? createElement(
								'span',
								{
									style: {
										display: 'inline-block',
										padding: '2px 8px',
										border: '1px solid #dcdcde',
										borderRadius: '3px',
										background: '#f0f0f1',
										color: '#50575e',
										fontFamily:
												'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
										fontSize: '12px',
										lineHeight: 1.4,
										maxWidth: '100%',
										overflow: 'hidden',
										textOverflow: 'ellipsis',
										verticalAlign: 'middle',
									},
								},
								item.category,
							)
							: null,
				},
				{
					id: 'description',
					label: __( 'Description', 'acrossai-mcp-manager' ),
					enableGlobalSearch: true,
					// Constrain the description column so long copy doesn't
					// push the sortable columns off-screen. CSS below also
					// wraps and truncates gracefully.
					width: '20%',
					maxWidth: '20%',
				},
				{
					id: 'is_exposed',
					label: __( 'Exposed', 'acrossai-mcp-manager' ),
					enableSorting: false,
					enableHiding: false,
					render: ( { item } ) =>
						createElement( ToggleControl, {
							__nextHasNoMarginBottom: true,
							checked: !! item.is_exposed,
							onChange: ( next ) => saveOne( item.slug, next ),
							'aria-label': sprintf(
								/* translators: %s: ability slug */
								__(
									'Toggle exposure for %s',
									'acrossai-mcp-manager',
								),
								item.slug,
							),
						} ),
				},
			],
			// eslint-disable-next-line react-hooks/exhaustive-deps
			[],
		);

		// Built-in bulk actions are registered so DataViews renders the row
		// selection checkbox column (DataViews couples per-row checkboxes
		// to `supportsBulk: true` action availability — removing all actions
		// also removes the checkboxes). The per-row Actions column is then
		// hidden via CSS so only the checkboxes remain visible. Our custom
		// bulk-actions bar above the table drives the Expose/Hide flow via
		// its own inline handlers.
		const builtinActions = useMemo(
			() => [
				{
					id: 'expose',
					label: __( 'Expose selected', 'acrossai-mcp-manager' ),
					supportsBulk: true,
					callback: ( actionItems ) => saveMany( actionItems, true ),
				},
				{
					id: 'hide',
					label: __( 'Hide selected', 'acrossai-mcp-manager' ),
					supportsBulk: true,
					callback: ( actionItems ) => saveMany( actionItems, false ),
				},
			],
			// eslint-disable-next-line react-hooks/exhaustive-deps
			[],
		);

		// Additive-only merge invariant — extensions may append, never
		// remove or overwrite, built-in field/action ids (FR-026 / FR-029).
		const finalFields = useMemo( () => {
			const extra = safeApplyFilters(
				'acrossaiMcpManager.abilities.fields',
				builtinFields,
				filterCtx,
			);
			const builtinIds = new Set( builtinFields.map( ( f ) => f.id ) );
			const additions = ( Array.isArray( extra ) ? extra : [] ).filter(
				( f ) => f && ! builtinIds.has( f.id ),
			);
			return [ ...builtinFields, ...additions ];
		}, [ builtinFields, filterCtx ] );

		// Keep `view.fields` in sync with `finalFields` so extension-added
		// columns are visible by default. Without this, a companion plugin's
		// filter would produce a field the user has to explicitly enable via
		// the column-visibility toggle.
		useEffect( () => {
			setView( ( current ) => {
				const currentSet = new Set( current.fields || [] );
				const builtinIds = new Set( builtinFields.map( ( f ) => f.id ) );
				const additions = finalFields
					.map( ( f ) => f.id )
					.filter( ( id ) => ! builtinIds.has( id ) && ! currentSet.has( id ) );
				if ( additions.length === 0 ) {
					return current;
				}
				return { ...current, fields: [ ...( current.fields || [] ), ...additions ] };
			} );
		}, [ finalFields, builtinFields ] );

		const finalActions = useMemo( () => {
			const extra = safeApplyFilters(
				'acrossaiMcpManager.abilities.actions',
				builtinActions,
				filterCtx,
			);
			const builtinIds = new Set( builtinActions.map( ( a ) => a.id ) );
			const additions = ( Array.isArray( extra ) ? extra : [] ).filter(
				( a ) => a && ! builtinIds.has( a.id ),
			);
			return [ ...builtinActions, ...additions ];
		}, [ builtinActions, filterCtx ] );

		const exposedCount = decoratedItems.filter( ( i ) => i.is_exposed )
			.length;

		// Apply the custom exposure filter BEFORE DataViews sees the data.
		// `decoratedItems` stays untouched so the header counter still shows
		// "N of M exposed" against the full ability list.
		const dataForView = useMemo( () => {
			if ( exposureFilter === 'exposed' ) {
				return decoratedItems.filter( ( i ) => !! i.is_exposed );
			}
			if ( exposureFilter === 'hidden' ) {
				return decoratedItems.filter( ( i ) => ! i.is_exposed );
			}
			if ( exposureFilter === 'overridden' ) {
				// F082 — filter to only abilities with an explicit row in
				// acrossai_mcp_server_abilities (has_override=true). Useful on
				// `policy='expose'`/`policy='hide'` servers to see the operator's
				// per-ability opt-outs at a glance.
				return decoratedItems.filter( ( i ) => !! i.has_override );
			}
			return decoratedItems;
		}, [ decoratedItems, exposureFilter ] );

		// DataViews does NOT filter/sort/paginate on its own — the consumer
		// must apply the view state to the data before passing it in. The
		// `filterSortAndPaginate` helper shipped alongside DataViews does all
		// three in one call and returns `{ data, paginationInfo }`.
		const { data: shownData, paginationInfo: shownPagination } = useMemo(
			() => filterSortAndPaginate( dataForView, view, finalFields ),
			[ dataForView, view, finalFields ],
		);

		// Unique category list — drives the "All categories" dropdown.
		// MUST live above the early returns so hook order stays stable
		// across render passes (React rules of hooks).
		const categoryOptions = useMemo( () => {
			const seen = new Set();
			decoratedItems.forEach( ( it ) => {
				if ( it.category ) {
					seen.add( it.category );
				}
			} );
			const sorted = Array.from( seen ).sort();
			return [
				{ value: '', label: __( 'All categories', 'acrossai-mcp-manager' ) },
				...sorted.map( ( c ) => ( { value: c, label: c } ) ),
			];
		}, [ decoratedItems ] );

		const typeOptions = useMemo(
			() => [
				{ value: '', label: __( 'All types', 'acrossai-mcp-manager' ) },
				{ value: 'tool', label: __( 'Tool', 'acrossai-mcp-manager' ) },
				{ value: 'prompt', label: __( 'Prompt', 'acrossai-mcp-manager' ) },
				{ value: 'resource', label: __( 'Resource', 'acrossai-mcp-manager' ) },
			],
			[],
		);

		if ( loading ) {
			// Full-screen branded loading overlay — centered pulsing AcrossAI
			// icon, mirroring the Quick Connect wizard's hydrate/busy overlay
			// (`qs__initial-loading--overlay`). Shown on initial load and
			// during Enable All / Disable All / Reset policy transitions.
			// Falls back to the plain Spinner if the icon URL isn't localized.
			if ( ! config.iconUrl ) {
				return createElement( Spinner );
			}
			return createElement(
				'div',
				{
					className: 'acrossai-mcp-abilities-loading',
					role: 'alert',
					'aria-live': 'assertive',
					'aria-busy': 'true',
				},
				createElement( 'img', {
					className: 'acrossai-mcp-abilities-loading__icon',
					src: config.iconUrl,
					alt: '',
					'aria-hidden': 'true',
				} ),
				createElement(
					'span',
					{ className: 'screen-reader-text' },
					__( 'Loading…', 'acrossai-mcp-manager' ),
				),
			);
		}
		if ( error ) {
			return createElement(
				Notice,
				{ status: 'error', isDismissible: false },
				error,
			);
		}

		// Read/write filter values via `view.filters`. DataViews reads this
		// array and applies the filters to its row set; we drive it from the
		// custom SelectControls below. These are plain helpers, not hooks,
		// so they can safely live after the early returns.
		const readFilter = ( field ) => {
			const entry = ( view.filters || [] ).find(
				( f ) => f.field === field,
			);
			return entry ? entry.value : '';
		};

		function writeFilter( field, value ) {
			setView( ( current ) => {
				const others = ( current.filters || [] ).filter(
					( f ) => f.field !== field,
				);
				const next = value
					? [
						...others,
						{ field, operator: 'is', value },
					]
					: others;
				return { ...current, filters: next };
			} );
		}

		// F082 — override count derived from server-computed `has_override`.
		const overrideCount = decoratedItems.filter( ( i ) => i.has_override ).length;

		// F082 — header pill copy (FR-019, spec Clarifications Q4).
		// Rendered with role="status" + aria-live="polite" so screen readers
		// announce policy transitions after Enable All / Disable All fire
		// without interrupting current speech.
		let policyPillCopy;
		if ( policy === 'expose' ) {
			policyPillCopy = __( 'Default policy: Expose every ability by default', 'acrossai-mcp-manager' );
		} else if ( policy === 'hide' ) {
			policyPillCopy = __( 'Default policy: Hide every ability by default', 'acrossai-mcp-manager' );
		} else {
			policyPillCopy = __( "Default policy: Use each ability's own default", 'acrossai-mcp-manager' );
		}

		// F082 — resolver-driven counter (spec FR-005 + SC-006).
		let counterCopy;
		if ( policy === 'expose' ) {
			counterCopy = sprintf(
				/* translators: 1: total ability count, 2: override count */
				_n(
					'All %1$d exposed — default policy: expose. %2$d override.',
					'All %1$d exposed — default policy: expose. %2$d overrides.',
					overrideCount,
					'acrossai-mcp-manager',
				),
				decoratedItems.length,
				overrideCount,
			);
		} else if ( policy === 'hide' ) {
			counterCopy = sprintf(
				/* translators: %d: override count */
				_n(
					'All hidden — default policy: hide. %d override.',
					'All hidden — default policy: hide. %d overrides.',
					overrideCount,
					'acrossai-mcp-manager',
				),
				overrideCount,
			);
		} else {
			counterCopy = sprintf(
				/* translators: 1: exposed count, 2: total count */
				_n(
					'%1$d of %2$d ability exposed on this server.',
					'%1$d of %2$d abilities exposed on this server.',
					decoratedItems.length,
					'acrossai-mcp-manager',
				),
				exposedCount,
				decoratedItems.length,
			);
		}

		// F082 — confirm-modal copy per target policy. The 'per-ability' branch
		// backs the "Reset to Ability Defaults" button; the REST enum already
		// accepts all three values.
		const confirmTitles = {
			expose: __( 'Expose every ability by default?', 'acrossai-mcp-manager' ),
			hide: __( 'Hide every ability by default?', 'acrossai-mcp-manager' ),
			'per-ability': __( "Reset to each ability's own default?", 'acrossai-mcp-manager' ),
		};
		const confirmBodies = {
			expose: __(
				'Expose every ability on this server by default? Any per-ability overrides will be cleared and future abilities will be exposed automatically.',
				'acrossai-mcp-manager',
			),
			hide: __(
				'Hide every ability on this server by default? Any per-ability overrides will be cleared and future abilities will be hidden automatically.',
				'acrossai-mcp-manager',
			),
			'per-ability': __(
				"Reset this server to per-ability defaults? Any per-ability overrides will be cleared and each ability will follow its author's default (the ability's public flag).",
				'acrossai-mcp-manager',
			),
		};

		return createElement(
			'div',
			{ className: 'acrossai-mcp-abilities-root' },
			// F082 — "Default policy" panel: pill + counter + policy-level
			// actions (Enable All / Disable All / Reset to Ability Defaults).
			// Server-wide policy controls live here, visually separated from
			// the selection-scoped bulk bar below. The whole-list actions flip
			// the SERVER-LEVEL policy (POST /abilities/policy), so the intent
			// survives future ability registrations: on `policy='expose'`
			// servers, a mu-plugin's newly-registered ability is auto-exposed
			// with no admin action. The button matching the current policy is
			// disabled — FR-015 no-op suppression makes it a silent no-op
			// server-side (same policy → overrides NOT cleared), so offering
			// it would mislead. Per spec Clarifications Q3 the confirm prompt
			// uses `<Modal>` (not `window.confirm`) so it matches the
			// DataViews aesthetic and inherits focus-trap + Esc.
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-policy' },
				createElement(
					'div',
					{ className: 'acrossai-mcp-abilities-policy__status' },
					// FR-019: role=status + aria-live=polite stay on the pill
					// so screen readers announce policy transitions.
					createElement(
						'span',
						{
							className:
								'acrossai-mcp-abilities-policy__pill is-policy-' +
								policy,
							role: 'status',
							'aria-live': 'polite',
						},
						policyPillCopy,
					),
					createElement(
						'p',
						{
							className:
								'description acrossai-mcp-abilities-policy__counter',
						},
						counterCopy,
					),
				),
				createElement(
					'div',
					{ className: 'acrossai-mcp-abilities-policy__actions' },
					createElement(
						Button,
						{
							variant: 'secondary',
							size: 'compact',
							disabled: policy === 'expose',
							onClick: () =>
								setConfirmModal( { policy: 'expose' } ),
						},
						__( 'Enable All', 'acrossai-mcp-manager' ),
					),
					createElement(
						Button,
						{
							variant: 'secondary',
							size: 'compact',
							disabled: policy === 'hide',
							onClick: () =>
								setConfirmModal( { policy: 'hide' } ),
						},
						__( 'Disable All', 'acrossai-mcp-manager' ),
					),
					createElement(
						Button,
						{
							variant: 'secondary',
							size: 'compact',
							disabled: policy === 'per-ability',
							onClick: () =>
								setConfirmModal( { policy: 'per-ability' } ),
						},
						__( 'Reset to Ability Defaults', 'acrossai-mcp-manager' ),
					),
				),
			),
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-toolbar' },
				createElement( SearchControl, {
					__nextHasNoMarginBottom: true,
					className: 'acrossai-mcp-abilities-toolbar__search',
					label: __(
						'Search abilities',
						'acrossai-mcp-manager',
					),
					placeholder: __(
						'Search name, label or description…',
						'acrossai-mcp-manager',
					),
					value: view.search || '',
					onChange: ( v ) =>
						setView( ( current ) => ( {
							...current,
							search: v,
						} ) ),
				} ),
				createElement( SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Category', 'acrossai-mcp-manager' ),
					hideLabelFromVision: true,
					value: readFilter( 'category' ),
					options: categoryOptions,
					onChange: ( v ) => writeFilter( 'category', v ),
				} ),
				createElement( SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Type', 'acrossai-mcp-manager' ),
					hideLabelFromVision: true,
					value: readFilter( 'type' ),
					options: typeOptions,
					onChange: ( v ) => writeFilter( 'type', v ),
				} ),
				createElement( SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Exposure', 'acrossai-mcp-manager' ),
					hideLabelFromVision: true,
					value: exposureFilter,
					options: [
						{
							value: '',
							label: __(
								'All exposure',
								'acrossai-mcp-manager',
							),
						},
						{
							value: 'exposed',
							label: __(
								'Only exposed',
								'acrossai-mcp-manager',
							),
						},
						{
							value: 'hidden',
							label: __(
								'Only hidden',
								'acrossai-mcp-manager',
							),
						},
						{
							// F082 — fourth option; requires server-truth `has_override`.
							value: 'overridden',
							label: __(
								'Only overridden',
								'acrossai-mcp-manager',
							),
						},
					],
					onChange: setExposureFilter,
				} ),
			),
			// Bulk-actions bar — always visible, buttons disabled when nothing
			// is selected. Replaces DataViews' native bulk-action strip which
			// lives inside the toolbar chrome we've hidden.
			createElement(
				'div',
				{
					className: 'acrossai-mcp-abilities-bulk',
				},
				createElement(
					'span',
					{ className: 'acrossai-mcp-abilities-bulk__count' },
					sprintf(
						/* translators: %d: number of selected rows */
						_n(
							'%d selected',
							'%d selected',
							selection.length,
							'acrossai-mcp-manager',
						),
						selection.length,
					),
				),
				createElement(
					Button,
					{
						variant: 'primary',
						size: 'compact',
						disabled: selection.length === 0,
						onClick: () => {
							const chosen = decoratedItems.filter( ( i ) =>
								selection.includes( i.slug ),
							);
							saveMany( chosen, true ).then( () =>
								setSelection( [] ),
							);
						},
					},
					__( 'Expose selected', 'acrossai-mcp-manager' ),
				),
				createElement(
					Button,
					{
						variant: 'secondary',
						size: 'compact',
						disabled: selection.length === 0,
						onClick: () => {
							const chosen = decoratedItems.filter( ( i ) =>
								selection.includes( i.slug ),
							);
							saveMany( chosen, false ).then( () =>
								setSelection( [] ),
							);
						},
					},
					__( 'Hide selected', 'acrossai-mcp-manager' ),
				),
				createElement(
					Button,
					{
						variant: 'link',
						size: 'compact',
						disabled: selection.length === 0,
						onClick: () => setSelection( [] ),
					},
					__( 'Clear', 'acrossai-mcp-manager' ),
				),
			),
			// F082 — confirm modal for the three policy transitions (spec Q3, FR-019).
			confirmModal &&
				createElement(
					Modal,
					{
						title: confirmTitles[ confirmModal.policy ],
						onRequestClose: () => setConfirmModal( null ),
						className: 'acrossai-mcp-abilities-policy-modal',
					},
					createElement(
						'p',
						null,
						confirmBodies[ confirmModal.policy ],
					),
					createElement(
						'div',
						{ className: 'acrossai-mcp-abilities-policy-modal__actions' },
						createElement(
							Button,
							{
								variant: 'tertiary',
								onClick: () => setConfirmModal( null ),
							},
							__( 'Cancel', 'acrossai-mcp-manager' ),
						),
						createElement(
							Button,
							{
								variant: 'primary',
								// Hide-everything is the destructive transition —
								// flag it so the Confirm button renders red.
								isDestructive: confirmModal.policy === 'hide',
								onClick: () => {
									const nextPolicy = confirmModal.policy;
									setConfirmModal( null );
									// A policy transition rewrites the whole
									// table, so — unlike single-toggle saves —
									// show the full-tab Spinner for the entire
									// POST + refresh round-trip.
									setLoading( true );
									apiFetch( {
										path: `/${ config.namespace }/servers/${ config.serverId }/abilities/policy`,
										method: 'POST',
										data: { policy: nextPolicy },
									} )
										.then( ( res ) => {
											if ( res && typeof res.abilities_default_policy === 'string' ) {
												setPolicy( res.abilities_default_policy );
											}
											// Policy transition clears every override
											// server-side; the authoritative post-change
											// state is a re-fetch.
											return refreshFromServer();
										} )
										.catch( ( e ) =>
											setError( e && e.message ? e.message : String( e ) ),
										)
										.finally( () => setLoading( false ) );
								},
							},
							__( 'Confirm', 'acrossai-mcp-manager' ),
						),
					),
				),
			createElement( DataViews, {
				data: shownData,
				fields: finalFields,
				view,
				onChangeView: setView,
				actions: finalActions,
				defaultLayouts: { table: {} },
				getItemId: ( item ) => item.slug,
				paginationInfo: shownPagination,
				selection,
				onChangeSelection: setSelection,
			} ),
			// Custom footer: [Select all] · [pagination] · [N of M Items]
			createElement( AbilitiesFooter, {
				view,
				setView,
				shownData,
				selection,
				setSelection,
				totalItems: decoratedItems.length,
				paginationInfo: shownPagination,
			} ),
		);
	}

	// React 18+ modern rendering path — legacy `render()` triggers a
	// deprecation warning in the browser console and puts the app in
	// React-17-compat mode. `createRoot` is re-exported from
	// `@wordpress/element` for exactly this migration.
	createRoot( mount ).render( createElement( App ) );
}() );
