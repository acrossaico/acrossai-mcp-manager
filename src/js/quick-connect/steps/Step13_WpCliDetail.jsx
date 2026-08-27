/**
 * F069 — Step 13: WP-CLI detail.
 *
 * Only shown when user picked "WP-CLI" on Step 7.
 *
 * WP-CLI commands are NOT sourced from ConnectionMethodRegistry (it doesn't
 * expose a WP-CLI category — WP-CLI is a static command, not a DTO). The
 * three commands here are hardcoded reference material; server slug is
 * interpolated from wizardState.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import CodeBlock from '../components/CodeBlock.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';

const Step13_WpCliDetail = () => {
	const { state } = useWizardState();

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

	return (
		<div>
			<h3 style={ { margin: '0 0 6px', fontSize: 16 } }>
				{ __(
					'STDIO Transport (Local / Subprocess Mode)',
					'acrossai-mcp-manager'
				) }
			</h3>
			<p style={ { fontSize: 13, color: '#757575', lineHeight: 1.55, marginBottom: 20 } }>
				{ __(
					'MCP clients can also connect by launching WP-CLI as a subprocess instead of calling the HTTP endpoint. Ideal for local WordPress installs — no credentials transmitted over the network.',
					'acrossai-mcp-manager'
				) }
			</p>

			<div style={ { marginBottom: 16 } }>
				<strong>
					{ __( 'List all registered MCP servers', 'acrossai-mcp-manager' ) }
				</strong>
				<CodeBlock variant="inline">wp mcp-adapter list</CodeBlock>
			</div>

			<div style={ { marginBottom: 16 } }>
				<strong>
					{ __(
						'Start the MCP server as a subprocess',
						'acrossai-mcp-manager'
					) }
				</strong>
				<CodeBlock variant="inline">
					{ `wp mcp-adapter serve --server=${ server.slug } --user=admin` }
				</CodeBlock>
			</div>

			<Notice status="info">
				{ __(
					'Choose STDIO for local dev + CI. Choose HTTP (via the MCP Client or Connectors path) when the client is a hosted app that can\'t launch subprocesses on your machine.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		</div>
	);
};

export default Step13_WpCliDetail;
