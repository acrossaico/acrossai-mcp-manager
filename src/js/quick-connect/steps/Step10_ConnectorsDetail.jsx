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
					<Notice status="info">
						{ __(
							'This connector supports Dynamic Client Registration only — paste the MCP URL above into your AI client and it will register itself. No manual credentials to generate.',
							'acrossai-mcp-manager'
						) }
					</Notice>
				</div>
			) }
		</div>
	);
};

export default Step10_ConnectorsDetail;
