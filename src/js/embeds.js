/**
 * Feature 037 — Embeds tab React entry (DataViews + DataForm redesign).
 *
 * Mounts a React app at `#acrossai-mcp-embeds-root` on the per-server
 * Embeds tab. Uses:
 *   - `@wordpress/dataviews` DataForm for the master toggle (single-field form)
 *   - `@wordpress/dataviews` DataViews for the per-DTO grid (with category
 *     filter + bulk enable/disable actions)
 *   - Auto-save on every toggle change (F017 abilities pattern — no Save
 *     button; each toggle POSTs the full state via apiFetch)
 *
 * Constitution §IV compliance: DataForm/DataViews per constitution mandate;
 * D-DATAVIEWS + D37 sanctioned package family only. B25 defense: nonce
 * middleware only, NEVER rootURL middleware.
 *
 * State model:
 *   master: bool
 *   groups: [{ key, label, priority, dtos: [{ slug, name, icon, enabled }] }]
 *
 * DataViews rows are flattened DTOs with composite `id = "{transport_key}:{dto_slug}"`
 * so DataViews' row-selection tracks stable keys across category groups.
 *
 * @since 0.1.10
 * @package
 */

import { createRoot, createElement, useCallback, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { DataViews, DataForm, filterSortAndPaginate } from '@wordpress/dataviews';
import { ToggleControl, Notice, Spinner } from '@wordpress/components';

import '../scss/embeds.scss';

const el = createElement;

( function() {
	const mount = document.getElementById( 'acrossai-mcp-embeds-root' );
	if ( ! mount ) {
		return;
	}

	const bootstrap = window.acrossaiMcpEmbeds || {};
	if ( ! bootstrap.serverId ) {
		mount.innerHTML =
			'<p><em>' +
			__( 'Missing server context — cannot mount Embeds tab.', 'acrossai-mcp-manager' ) +
			'</em></p>';
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.nonce ) );

	const restPath =
		'/' + bootstrap.namespace + '/servers/' + bootstrap.serverId + '/embeds';

	/**
	 * Flatten groups → DataViews rows.
	 *
	 * @param {Array} groups From bootstrap OR POST response.
	 * @return {Array} Row objects with { id, transport_key, transport_label, slug, name, icon, category, enabled }
	 */
	function flattenGroups( groups ) {
		const rows = [];
		( groups || [] ).forEach( ( group ) => {
			( group.dtos || [] ).forEach( ( dto ) => {
				rows.push( {
					id: group.key + ':' + dto.slug,
					transport_key: group.key,
					transport_label: group.label,
					slug: dto.slug,
					name: dto.name,
					icon: dto.icon || '',
					category: group.label,
					enabled: !! dto.enabled,
				} );
			} );
		} );
		return rows;
	}

	/**
	 * Rebuild the nested items state from DataViews rows for POSTing.
	 *
	 * @param {Array} rows Current flattened rows.
	 * @return {Object} { transport_key: { dto_slug: bool } }
	 */
	function buildItemsFromRows( rows ) {
		const items = {};
		rows.forEach( ( row ) => {
			if ( ! items[ row.transport_key ] ) {
				items[ row.transport_key ] = {};
			}
			items[ row.transport_key ][ row.slug ] = !! row.enabled;
		} );
		return items;
	}

	function EmbedsApp() {
		const [ master, setMaster ] = useState( !! bootstrap.master );
		const [ rows, setRows ] = useState( () => flattenGroups( bootstrap.groups ) );
		const [ saving, setSaving ] = useState( false );
		const [ notice, setNotice ] = useState( null );

		// DataViews view state — table layout with per-page pagination
		// (small dataset, per_page 100 keeps everything on one page).
		const [ view, setView ] = useState( {
			type: 'table',
			search: '',
			page: 1,
			perPage: 100,
			fields: [ 'category', 'enabled' ],
			titleField: 'name_display',
			showTitle: true,
			showMedia: false,
			showDescription: false,
			filters: [],
			layout: {},
		} );

		/**
		 * Save the full state to the REST endpoint. Called after every
		 * toggle change + every bulk action. Matches F017 abilities
		 * auto-save pattern.
		 */
		const saveState = useCallback(
			( nextMaster, nextRows ) => {
				setSaving( true );
				setNotice( null );
				apiFetch( {
					path: restPath,
					method: 'POST',
					data: {
						master: nextMaster,
						items: buildItemsFromRows( nextRows ),
					},
				} )
					.then( ( response ) => {
						setSaving( false );
						setMaster( !! response.master );
						setRows( flattenGroups( response.groups ) );
					} )
					.catch( ( err ) => {
						setSaving( false );
						const message =
							err && err.message
								? err.message
								: __( 'Save failed — see browser console.', 'acrossai-mcp-manager' );
						setNotice( {
							status: 'error',
							message,
						} );
						// eslint-disable-next-line no-console
						console.error( 'F037 Embeds save failed:', err );
					} );
			},
			[],
		);

		/**
		 * Toggle a single row's enabled state + trigger auto-save.
		 */
		const toggleRow = useCallback(
			( id, value ) => {
				setRows( ( prev ) => {
					const next = prev.map( ( r ) =>
						r.id === id ? { ...r, enabled: !! value } : r,
					);
					saveState( master, next );
					return next;
				} );
			},
			[ master, saveState ],
		);

		/**
		 * Bulk toggle: apply `enabled` to every row in `ids`.
		 */
		const bulkToggle = useCallback(
			( ids, enabled ) => {
				const idSet = new Set( ids );
				setRows( ( prev ) => {
					const next = prev.map( ( r ) =>
						idSet.has( r.id ) ? { ...r, enabled: !! enabled } : r,
					);
					saveState( master, next );
					return next;
				} );
			},
			[ master, saveState ],
		);

		/**
		 * Master toggle change → auto-save.
		 */
		const onMasterChange = useCallback(
			( nextMaster ) => {
				const val = !! nextMaster;
				setMaster( val );
				saveState( val, rows );
			},
			[ rows, saveState ],
		);

		// DataViews fields definition.
		const fields = useMemo(
			() => [
				{
					id: 'name_display',
					label: __( 'Name', 'acrossai-mcp-manager' ),
					enableGlobalSearch: true,
					getValue: ( { item } ) => item.name,
					render: ( { item } ) => {
						// Icon may be emoji (text) OR URL (AI connectors).
						// Render URLs as <img>; emoji as text; empty renders nothing.
						const isUrl =
							typeof item.icon === 'string' &&
							/^https?:\/\//i.test( item.icon );

						let iconNode = null;
						if ( item.icon && isUrl ) {
							iconNode = el( 'img', {
								key: 'icon',
								src: item.icon,
								alt: '',
								className: 'acrossai-mcp-embeds-app__icon-img',
							} );
						} else if ( item.icon ) {
							iconNode = el(
								'span',
								{
									key: 'icon',
									className: 'acrossai-mcp-embeds-app__icon',
									'aria-hidden': 'true',
								},
								item.icon,
							);
						}

						return el(
							'span',
							{ className: 'acrossai-mcp-embeds-app__name' },
							iconNode,
							el( 'span', { key: 'name' }, item.name ),
						);
					},
				},
				{
					id: 'category',
					label: __( 'Category', 'acrossai-mcp-manager' ),
					getValue: ( { item } ) => item.category,
					render: ( { item } ) =>
						el(
							'span',
							{ className: 'acrossai-mcp-embeds-app__category-chip' },
							item.category,
						),
					filterBy: { operators: [ 'is', 'isNot' ] },
					elements: Array.from(
						new Set( rows.map( ( r ) => r.category ) ),
					).map( ( cat ) => ( {
						value: cat,
						label: cat,
					} ) ),
				},
				{
					id: 'enabled',
					label: __( 'Enabled', 'acrossai-mcp-manager' ),
					enableSorting: false,
					enableHiding: false,
					render: ( { item } ) =>
						el( ToggleControl, {
							__nextHasNoMarginBottom: true,
							checked: !! item.enabled,
							disabled: ! master,
							onChange: ( next ) => toggleRow( item.id, next ),
							'aria-label': sprintf(
								/* translators: 1: DTO name, 2: category */
								__( 'Toggle %1$s (%2$s)', 'acrossai-mcp-manager' ),
								item.name,
								item.category,
							),
						} ),
				},
			],
			[ rows, master, toggleRow ],
		);

		// Bulk actions — enable/disable selected rows.
		const actions = useMemo(
			() => [
				{
					id: 'bulk-enable',
					label: __( 'Enable selected', 'acrossai-mcp-manager' ),
					supportsBulk: true,
					isEligible: () => master,
					callback: ( items ) => {
						bulkToggle( items.map( ( i ) => i.id ), true );
					},
				},
				{
					id: 'bulk-disable',
					label: __( 'Disable selected', 'acrossai-mcp-manager' ),
					supportsBulk: true,
					isEligible: () => master,
					callback: ( items ) => {
						bulkToggle( items.map( ( i ) => i.id ), false );
					},
				},
			],
			[ master, bulkToggle ],
		);

		// filterSortAndPaginate handles all view state (search, filter, sort, paginate).
		const { data: viewData, paginationInfo } = useMemo(
			() => filterSortAndPaginate( rows, view, fields ),
			[ rows, view, fields ],
		);

		// DataForm for the master toggle — single-field boolean form.
		const masterFormFields = useMemo(
			() => [
				{
					id: 'master',
					label: __( 'Enable frontend embeds for this server', 'acrossai-mcp-manager' ),
					type: 'boolean',
					Edit: 'toggle',
				},
			],
			[],
		);

		const masterFormLayout = useMemo(
			() => ( {
				type: 'regular',
				fields: [ 'master' ],
			} ),
			[],
		);

		return el(
			'div',
			{ className: 'acrossai-mcp-embeds-app' },
			notice &&
				el(
					Notice,
					{
						status: notice.status,
						isDismissible: true,
						onRemove: () => setNotice( null ),
					},
					notice.message,
				),
			saving &&
				el(
					'div',
					{ className: 'acrossai-mcp-embeds-app__saving-badge' },
					el( Spinner ),
					__( 'Saving…', 'acrossai-mcp-manager' ),
				),
			el(
				'div',
				{ className: 'acrossai-mcp-embeds-app__master' },
				el( DataForm, {
					data: { master },
					fields: masterFormFields,
					form: masterFormLayout,
					onChange: ( edits ) => {
						if ( 'master' in edits ) {
							onMasterChange( edits.master );
						}
					},
				} ),
				el(
					'p',
					{ className: 'description' },
					__(
						'When OFF, no shortcode or block output is rendered for this server regardless of per-item toggles below.',
						'acrossai-mcp-manager',
					),
				),
			),
			el(
				'div',
				{
					className:
						'acrossai-mcp-embeds-app__dataviews' +
						( master ? '' : ' is-disabled' ),
				},
				el( DataViews, {
					data: viewData,
					fields,
					view,
					onChangeView: setView,
					actions,
					paginationInfo,
					defaultLayouts: { table: {} },
				} ),
			),
		);
	}

	const root = createRoot( mount );
	root.render( el( EmbedsApp ) );
}() );
