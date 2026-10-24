/**
 * DataViews field definitions for the activity log.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { formatBytes, formatDuration, connectionLabel } from './format';

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
export function getFields( showServer ) {
	const fields = [
		{
			id: 'created_at_local',
			label: __( 'Time', 'acrossai-mcp-manager' ),
			enableSorting: true,
			enableHiding: false,
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
		},
		{
			id: 'component_name',
			label: __( 'Method / name', 'acrossai-mcp-manager' ),
			enableSorting: true,
			getValue: ( { item } ) => {
				const name = item.component_name || '—';

				return item.method ? `${ item.method } · ${ name }` : name;
			},
		},
		{
			id: 'status',
			label: __( 'Status', 'acrossai-mcp-manager' ),
			enableSorting: true,
			elements: [
				{ value: 'success', label: __( 'Success', 'acrossai-mcp-manager' ) },
				{ value: 'error', label: __( 'Error', 'acrossai-mcp-manager' ) },
				{ value: 'denied', label: __( 'Denied', 'acrossai-mcp-manager' ) },
				{ value: 'invalid_input', label: __( 'Invalid input', 'acrossai-mcp-manager' ) },
			],
		},
		{
			id: 'duration_ms',
			label: __( 'Time taken', 'acrossai-mcp-manager' ),
			enableSorting: true,
			getValue: ( { item } ) => formatDuration( item.duration_ms ),
		},
		{
			id: 'output_bytes',
			label: __( 'Returned', 'acrossai-mcp-manager' ),
			enableSorting: true,
			getValue: ( { item } ) => formatBytes( item.output_bytes ),
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

	columns.push( 'connection', 'component_name', 'status', 'duration_ms', 'output_bytes' );

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
