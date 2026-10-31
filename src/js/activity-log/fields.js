/**
 * DataViews field definitions for the activity log.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

import {
	formatBytes,
	formatDuration,
	formatApproxTokens,
	connectionLabel,
} from './format';

/**
 * Build the field set.
 *
 * One field set, parameterised by whether the Server column is shown: the
 * global page shows it, the per-server tab does not because every row there
 * has the same value.
 *
 * @param {boolean} showServer Whether to include the Server column.
 * @return {Array<Object>} DataViews fields.
 */
/*
 * Every column sets enableSorting: false, deliberately.
 *
 * Ordering is fixed at the data layer: Query::page_by_cursor() hardcodes
 * `ORDER BY created_at DESC, id DESC`, and its cursor is the created_at/id
 * pair, so another order would break paging. The REST route declares an
 * `orderby` argument but never passes it on.
 *
 * DataViews is handed one already-fetched page and does not reorder it, so a
 * sortable column here rendered a working-looking "Sort ascending" menu, set
 * the header arrow, and left the rows exactly where they were. Offering no
 * control is more honest than offering one that lies.
 *
 * Re-enable per column once page_by_cursor() can order by it — which means
 * giving the cursor a composite key, not just widening an enum.
 */
export function getFields( showServer, maxDurationMs = 0 ) {
	const fields = [
		{
			id: 'created_at_local',
			label: __( 'Time', 'acrossai-mcp-manager' ),
			enableSorting: false,
			enableHiding: false,
			render: ( { item } ) => {
				// "2026-10-31 16:15:23" in one run is hard to scan when every
				// row shares the date. Clock first, date underneath.
				const [ date, clock ] = String( item.created_at_local || '' ).split( ' ' );

				if ( ! clock ) {
					return date || '—';
				}

				return (
					<span className="acrossai-mcp-activity__stamp">
						<span className="acrossai-mcp-activity__clock">{ clock }</span>
						<span className="acrossai-mcp-activity__date">{ date }</span>
					</span>
				);
			},
		},
	];

	if ( showServer ) {
		fields.push( {
			id: 'server_slug',
			label: __( 'Server', 'acrossai-mcp-manager' ),
			enableSorting: false,
		} );
	}

	fields.push(
		{
			id: 'connection',
			label: __( 'Connection', 'acrossai-mcp-manager' ),
			enableSorting: false,
			getValue: ( { item } ) => {
				// An ability row has no connection type — it has an invocation
				// source. Showing "unknown" there would read as a failure to
				// detect rather than "this question does not apply".
				if ( item.event_type === 'ability' ) {
					return item.invoked_via || '—';
				}

				const client = [ item.client_name, item.client_version ].filter( Boolean ).join( ' ' );
				const how = item.connector_label || connectionLabel( item.connection_type );

				return client ? `${ client } · ${ how }` : how;
			},
			render: ( { item } ) => {
				if ( item.event_type === 'ability' ) {
					return item.invoked_via || '—';
				}

				const how = item.connector_label || connectionLabel( item.connection_type );

				return (
					<span className="acrossai-mcp-activity__conn">
						{ item.client_name && (
							<span className="acrossai-mcp-activity__conn-name">
								{ item.client_name }
							</span>
						) }
						{ item.client_version && (
							<span className="acrossai-mcp-activity__conn-version">
								{ item.client_version }
							</span>
						) }
						<span className="acrossai-mcp-activity__chip">{ how }</span>
					</span>
				);
			},
		},
		{
			id: 'component_name',
			label: __( 'Method / name', 'acrossai-mcp-manager' ),
			enableSorting: false,
			getValue: ( { item } ) => {
				const name = item.component_name || '—';

				return item.method ? `${ item.method } · ${ name }` : name;
			},
		},
		{
			id: 'status',
			label: __( 'Status', 'acrossai-mcp-manager' ),
			enableSorting: false,
			elements: [
				{ value: 'success', label: __( 'Success', 'acrossai-mcp-manager' ) },
				{ value: 'error', label: __( 'Error', 'acrossai-mcp-manager' ) },
				{ value: 'denied', label: __( 'Denied', 'acrossai-mcp-manager' ) },
				{ value: 'invalid_input', label: __( 'Invalid input', 'acrossai-mcp-manager' ) },
			],
			render: ( { item } ) => {
				const labels = {
					success: __( 'Success', 'acrossai-mcp-manager' ),
					error: __( 'Error', 'acrossai-mcp-manager' ),
					denied: __( 'Denied', 'acrossai-mcp-manager' ),
					invalid_input: __( 'Invalid input', 'acrossai-mcp-manager' ),
				};

				// The dot is drawn from currentColor, so colour and shape stay
				// in step and the label still carries the meaning on its own.
				return (
					<span
						className={ `acrossai-mcp-activity__status acrossai-mcp-activity__status--${ item.status }` }
					>
						{ labels[ item.status ] ?? item.status }
					</span>
				);
			},
		},
		{
			id: 'duration_ms',
			label: __( 'Time taken', 'acrossai-mcp-manager' ),
			enableSorting: false,
			// Numeric getValue, formatted render — see the Tokens column below.
			// Returning "9ms" here sorted it above "109ms" as text.
			getValue: ( { item } ) => item.duration_ms ?? 0,
			render: ( { item } ) => {
				const ms = item.duration_ms ?? 0;
				const pct =
					maxDurationMs > 0
						? Math.max( 2, Math.round( ( ms / maxDurationMs ) * 100 ) )
						: 0;

				return (
					<span className="acrossai-mcp-activity__dur">
						<span className="acrossai-mcp-activity__dur-track">
							<span
								className="acrossai-mcp-activity__dur-fill"
								style={ { width: `${ pct }%` } }
							/>
						</span>
						<span className="acrossai-mcp-activity__dur-value">
							{ formatDuration( ms ) }
						</span>
					</span>
				);
			},
		},
		{
			id: 'output_bytes',
			label: __( 'Returned', 'acrossai-mcp-manager' ),
			enableSorting: false,
			// Same: "999 B" sorted above "12.4 KB" while this returned text.
			getValue: ( { item } ) => item.output_bytes ?? 0,
			render: ( { item } ) => (
				<span className="acrossai-mcp-activity__num">
					{ formatBytes( item.output_bytes ) }
				</span>
			),
		},
		{
			id: 'output_tokens_approx',
			label: __( 'Tokens', 'acrossai-mcp-manager' ),
			enableSorting: false,
			// getValue stays numeric and render does the formatting. Sorting
			// uses getValue, so returning the formatted string here would order
			// the column as text — putting "≈ 865" above "≈ 3.1k".
			getValue: ( { item } ) => item.output_tokens_approx ?? 0,
			render: ( { item } ) => (
				<span className="acrossai-mcp-activity__num">
					{ formatApproxTokens( item.output_tokens_approx ) }
				</span>
			),
		},
	);

	return fields;
}

/**
 * Default view configuration.
 *
 * @param {boolean} showServer Whether the Server column is shown.
 * @return {Object} DataViews view.
 */
export function getDefaultView( showServer ) {
	const columns = [ 'created_at_local' ];

	if ( showServer ) {
		columns.push( 'server_slug' );
	}

	columns.push(
		'connection',
		'component_name',
		'status',
		'duration_ms',
		'output_bytes',
		'output_tokens_approx',
	);

	return {
		type: 'table',
		perPage: 25,
		page: 1,
		sort: { field: 'created_at_local', direction: 'desc' },
		search: '',
		filters: [],
		fields: columns,
	};
}
