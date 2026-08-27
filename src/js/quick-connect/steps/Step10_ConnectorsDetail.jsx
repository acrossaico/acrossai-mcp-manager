/**
 * F069 — Step 10: Connectors detail (provider tabs).
 *
 * Only shown when user picked Connectors on Step 7 AND acrossai-pro is
 * active. When pro is missing → Step 8 (pitch); when pro is inactive →
 * Step 9 (activate gate). Skip flags in App.jsx enforce that.
 *
 * Reads state.methods.ai_connectors (populated by T020 from
 * ConnectionMethodRegistry::get_all()). Provider count derived from array
 * length — NOT hardcoded to 4. Companion plugins that register additional
 * connectors auto-appear as extra tabs.
 *
 * Each tab shows the plugin's canonical MCP URL for the current server
 * with a Copy button.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import CodeBlock from '../components/CodeBlock.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';

const Step10_ConnectorsDetail = () => {
	const { state } = useWizardState();
	const bootstrap = window.acrossaiMcpQuickConnect || {};
	const connectors = state.methods.ai_connectors || [];
	const [ activeSlug, setActiveSlug ] = useState(
		connectors[ 0 ] ? connectors[ 0 ].slug : ''
	);

	const server = useMemo(
		() =>
			state.servers.find( ( s ) => s.id === state.wizardState.server_id ),
		[ state.servers, state.wizardState.server_id ]
	);

	if ( ! server ) {
		return (
			<Notice status="warning">
				{ __( 'No server selected.', 'acrossai-mcp-manager' ) }
			</Notice>
		);
	}

	if ( connectors.length === 0 ) {
		return (
			<Notice status="info">
				{ __(
					'No AI connectors registered on this site yet. Install AcrossAI Pro to enable connector providers.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		);
	}

	const activeConnector = connectors.find( ( c ) => c.slug === activeSlug );
	const mcpUrl = `${ bootstrap.siteUrl || '' }/wp-json/${ server.route_full }`;

	// F081 — walkthrough HTML defensively read from both potential DTO field
	// placements. acrossai-pro's DiscoveryConnectorAdapter is the source of
	// truth; when the paid plugin ships the companion PR that adds this
	// field, Step 10 lights up automatically. Until then (or when the paid
	// plugin isn't installed at all), walkthroughHtml stays empty and we
	// fall through to the existing "DCR-only" notice — zero regression.
	//
	// Trust boundary: acrossai-pro guarantees the string has passed through
	// wp_kses_post at write time (docblock note on get_mcp_url_setup_html).
	// See docs/planings-tasks/081-connector-walkthrough-panels.md.
	const walkthroughHtml = activeConnector
		? ( activeConnector.walkthrough_html
			|| ( activeConnector.meta && activeConnector.meta.walkthrough_html )
			|| '' )
		: '';

	return (
		<div>
			<div
				role="tablist"
				aria-label={ __( 'Connector providers', 'acrossai-mcp-manager' ) }
				style={ { display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' } }
			>
				{ connectors.map( ( c ) => {
					const isActive = c.slug === activeSlug;
					return (
						<button
							key={ c.slug }
							type="button"
							role="tab"
							aria-selected={ isActive }
							onClick={ () => setActiveSlug( c.slug ) }
							className={ isActive ? 'qs-btn' : 'qs-btn qs-btn--secondary' }
						>
							{ c.name || c.slug }
						</button>
					);
				} ) }
			</div>

			{ activeConnector && (
				<div>
					<h3 style={ { margin: '0 0 12px', fontSize: 16 } }>
						{ activeConnector.name || activeConnector.slug }
					</h3>
					<p style={ { fontSize: 13, color: '#757575', marginBottom: 8 } }>
						{ __(
							'MCP URL to paste into your AI client:',
							'acrossai-mcp-manager'
						) }
					</p>
					<CodeBlock variant="inline">{ mcpUrl }</CodeBlock>

					{ walkthroughHtml ? (
						<div
							className="acrossai-mcp-connector-panel__setup-embed"
							/* eslint-disable-next-line react/no-danger */
							dangerouslySetInnerHTML={ { __html: walkthroughHtml } }
						/>
					) : (
						<Notice status="info">
							{ __(
								'This connector supports Dynamic Client Registration only — paste the MCP URL above into your AI client and it will register itself. No manual credentials to generate.',
								'acrossai-mcp-manager'
							) }
						</Notice>
					) }
				</div>
			) }
		</div>
	);
};

export default Step10_ConnectorsDetail;
