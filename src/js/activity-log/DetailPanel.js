/**
 * Expanded detail for one activity event.
 *
 * @package
 */

import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';
import { formatContextCost, formatDuration, connectionLabel } from './format';

/**
 * One label/value row.
 *
 * Values render as React text children, which escape by default. No
 * dangerouslySetInnerHTML appears anywhere in this directory: every field
 * below originates outside the site, and this screen is shown to a
 * manage_options user — the highest-value XSS target on the install.
 *
 * @param {Object} props       Component props.
 * @param {string} props.label Field label.
 * @param {*}      props.value Field value.
 * @return {Element|null} The row, or null when empty.
 */
function Row( { label, value } ) {
	if ( value === null || value === undefined || value === '' ) {
		return null;
	}

	return (
		<div style={ { display: 'flex', gap: '12px', padding: '2px 0' } }>
			<span style={ { minWidth: '160px', color: '#646970' } }>{ label }</span>
			<span style={ { wordBreak: 'break-word' } }>{ String( value ) }</span>
		</div>
	);
}

/**
 * Detail panel. Fetches the full event — including previews, which the list
 * route deliberately omits — only when a row is actually opened.
 *
 * @param {Object} props       Component props.
 * @param {Object} props.event The list-row event.
 * @return {Element} The panel.
 */
export default function DetailPanel( { event } ) {
	const [ detail, setDetail ] = useState( null );
	const [ children, setChildren ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		let cancelled = false;

		const load = async () => {
			try {
				const full = await apiFetch( { path: `/acrossai-mcp-manager/v1/activity/${ event.id }` } );

				if ( cancelled ) {
					return;
				}

				setDetail( full );

				// Only request events have children; an ability event asking
				// for its own would be a guaranteed empty round trip.
				if ( full.event_type === 'request' ) {
					const kids = await apiFetch( {
						path: `/acrossai-mcp-manager/v1/activity/${ event.id }/children`,
					} );

					if ( ! cancelled ) {
						setChildren( kids?.events ?? [] );
					}
				}
			} catch ( e ) {
				if ( ! cancelled ) {
					setDetail( { error: e?.message ?? __( 'Could not load this event.', 'acrossai-mcp-manager' ) } );
				}
			} finally {
				if ( ! cancelled ) {
					setLoading( false );
				}
			}
		};

		load();

		return () => {
			cancelled = true;
		};
	}, [ event.id ] );

	if ( loading ) {
		return <Spinner />;
	}

	if ( detail?.error ) {
		return <p>{ detail.error }</p>;
	}

	return (
		<div style={ { padding: '8px 0', fontSize: '13px' } }>
			<Row label={ __( 'When', 'acrossai-mcp-manager' ) } value={ detail.created_at_local } />
			<Row label={ __( 'Server', 'acrossai-mcp-manager' ) } value={ detail.server_slug } />
			<Row
				label={ __( 'Connection', 'acrossai-mcp-manager' ) }
				value={ detail.connection_type ? connectionLabel( detail.connection_type ) : '' }
			/>
			<Row label={ __( 'Connector', 'acrossai-mcp-manager' ) } value={ detail.connector_label || detail.connector_slug } />
			<Row
				label={ __( 'Client', 'acrossai-mcp-manager' ) }
				value={ [ detail.client_name, detail.client_version ].filter( Boolean ).join( ' ' ) }
			/>
			<Row label={ __( 'Invoked via', 'acrossai-mcp-manager' ) } value={ detail.invoked_via } />
			<Row label={ __( 'Method', 'acrossai-mcp-manager' ) } value={ detail.method } />
			<Row label={ __( 'Name', 'acrossai-mcp-manager' ) } value={ detail.component_name } />
			{ /* Shown only when the client used a different spelling — the
			     sanitized form it sees in its own tools/list. Answers "my
			     client says this tool does not exist". */ }
			<Row label={ __( 'Name the client sent', 'acrossai-mcp-manager' ) } value={ detail.invoked_name } />
			<Row label={ __( 'Category', 'acrossai-mcp-manager' ) } value={ detail.component_category } />
			<Row label={ __( 'Status', 'acrossai-mcp-manager' ) } value={ detail.status } />
			<Row label={ __( 'Error code', 'acrossai-mcp-manager' ) } value={ detail.error_code } />
			<Row label={ __( 'Error', 'acrossai-mcp-manager' ) } value={ detail.error_message } />
			<Row label={ __( 'Duration', 'acrossai-mcp-manager' ) } value={ formatDuration( detail.duration_ms ) } />
			<Row
				label={ __( 'Context returned', 'acrossai-mcp-manager' ) }
				value={ formatContextCost( detail.output_bytes, detail.output_tokens_approx ) }
			/>
			<Row label={ __( 'Transport', 'acrossai-mcp-manager' ) } value={ detail.transport } />
			<Row label={ __( 'Protocol', 'acrossai-mcp-manager' ) } value={ detail.protocol_version } />
			<Row label={ __( 'Session', 'acrossai-mcp-manager' ) } value={ detail.session_id } />
			<Row label={ __( 'Request id', 'acrossai-mcp-manager' ) } value={ detail.request_id } />
			<Row label={ __( 'Source address', 'acrossai-mcp-manager' ) } value={ detail.ip_address } />
			<Row label={ __( 'User agent', 'acrossai-mcp-manager' ) } value={ detail.user_agent } />

			{ detail.input_preview && (
				<>
					<p style={ { margin: '12px 0 4px', color: '#646970' } }>
						{ __( 'Input (redacted, truncated)', 'acrossai-mcp-manager' ) }
					</p>
					<pre
						style={ {
							margin: 0,
							padding: '8px',
							background: '#f6f7f7',
							overflowX: 'auto',
							whiteSpace: 'pre-wrap',
							wordBreak: 'break-word',
						} }
					>
						{ detail.input_preview }
					</pre>
				</>
			) }

			{ detail.output_preview && (
				<>
					<p style={ { margin: '12px 0 4px', color: '#646970' } }>
						{ __( 'Result', 'acrossai-mcp-manager' ) }
					</p>
					<pre
						style={ {
							margin: 0,
							padding: '8px',
							background: '#f6f7f7',
							overflowX: 'auto',
							whiteSpace: 'pre-wrap',
							wordBreak: 'break-word',
						} }
					>
						{ detail.output_preview }
					</pre>
				</>
			) }

			{ detail.event_type === 'request' && (
				<>
					<p style={ { margin: '12px 0 4px', fontWeight: 600 } }>
						{ __( 'Abilities run', 'acrossai-mcp-manager' ) } ({ children.length })
					</p>
					{ children.length === 0 ? (
						<p className="description" style={ { margin: 0 } }>
							{ __(
								'None recorded. Either ability logging is off, or this call ran nothing through the abilities layer.',
								'acrossai-mcp-manager',
							) }
						</p>
					) : (
						<ul style={ { margin: 0, paddingLeft: '18px' } }>
							{ children.map( ( child ) => (
								<li key={ child.id }>
									{ child.component_name } — { child.status } ·{ ' ' }
									{ formatDuration( child.duration_ms ) }
								</li>
							) ) }
						</ul>
					) }
				</>
			) }

			{ /* Feature 097 adds a "Changes (N)" row here. The layout takes one
			     more row without restructuring, which is why it is a flat list
			     of Row components rather than a fixed grid. */ }
		</div>
	);
}
