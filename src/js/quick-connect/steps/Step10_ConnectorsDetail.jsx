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
 * F082 — under each tab's URL row, the pro plugin's rich per-provider
 * walkthrough panel ("How to connect Claude" etc.) is rendered via
 * useBelowFooter() so it sits AFTER the Back / Finish footer. Placing the
 * long walkthrough above the footer would push the primary CTA below the
 * fold and hide Finish. HTML is read from
 * state.methods.ai_connector_instructions, a sibling discovery lane
 * populated by acrossai-pro. Falls back to the "DCR-only" notice (in the
 * main content area, ABOVE the footer) when the map is empty (pro not
 * installed OR on a pre-F082 version) — zero regression on that path.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import CodeBlock from '../components/CodeBlock.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import { useBelowFooter } from '../hooks/useAdvanceGuard.js';

const escapeHtml = ( s ) =>
	String( s )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );

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

	const activeConnector = connectors.find( ( c ) => c.slug === activeSlug );
	const mcpUrl = server
		? `${ bootstrap.siteUrl || '' }/wp-json/${ server.route_full }`
		: '';

	// F082 — walkthrough HTML flows through its own discovery lane
	// (`state.methods.ai_connector_instructions`), a `{slug => html}` map
	// populated by acrossai-pro's DiscoveryConnectorAdapter::provide_ai_connector_instructions().
	// Mirrors the pattern used for the connector list itself — different
	// filter, same producer/consumer contract. Falls back to the DCR-only
	// notice (rendered inline in the main content area, above the footer)
	// when the map is empty (pro plugin not installed OR on a pre-F082
	// version) — zero regression.
	//
	// The pro plugin emits the HTML with the sentinel token
	// `__ACROSSAI_MCP_URL__` wherever the MCP URL should appear (its
	// discovery pass has no per-server context). We substitute the token
	// with the currently-selected server's real URL, HTML-escaped so a
	// pathological server URL cannot inject markup.
	//
	// Trust boundary: acrossai-pro guarantees the string has passed through
	// wp_kses_post at write time (docblock note on get_mcp_url_setup_html +
	// enforced in provide_ai_connector_instructions).
	const instructionsMap = state.methods.ai_connector_instructions || {};
	const rawInstructions = activeConnector
		? ( instructionsMap[ activeConnector.slug ] || '' )
		: '';
	const walkthroughHtml = rawInstructions
		? rawInstructions.replaceAll( '__ACROSSAI_MCP_URL__', escapeHtml( mcpUrl ) )
		: '';

	// Register the walkthrough panel to render BELOW the footer. Memoized so
	// useBelowFooter's effect only re-fires when the HTML actually changes
	// (switching connector pill or server) — avoids a set-state loop.
	// Guards: no panel when no server, no connectors, or empty walkthrough
	// map — the DCR-only fallback lives in the main content area above the
	// footer instead. Hook is called unconditionally to satisfy the Rules of
	// Hooks; early returns below are safe because this line always runs.
	const walkthroughNode = useMemo( () => {
		if ( ! server || connectors.length === 0 || ! walkthroughHtml ) {
			return null;
		}
		return (
			<div
				className="acrossai-mcp-connector-panel__setup-embed"
				/* eslint-disable-next-line react/no-danger */
				dangerouslySetInnerHTML={ { __html: walkthroughHtml } }
			/>
		);
	}, [ server, connectors.length, walkthroughHtml ] );
	useBelowFooter( walkthroughNode );

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

					{ /* DCR-only fallback stays in the main content area (above the
					     footer) so operators on pre-F082 acrossai-pro versions still
					     see a helpful hint next to the URL. When the walkthrough map
					     IS populated, the rich panel replaces this notice and
					     renders BELOW the footer via useBelowFooter() — keeps the
					     primary Finish CTA visible without scrolling past a long
					     guide. */ }
					{ ! walkthroughHtml && (
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
