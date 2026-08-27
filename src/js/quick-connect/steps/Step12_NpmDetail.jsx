/**
 * F069 — Step 12: npm detail (npx command).
 *
 * Only shown when user picked "npm" on Step 7.
 *
 * Reads state.methods.npm (populated by T020). Iterates over the DTO
 * list — currently 1 built-in method (`npx -y @acrossai/mcp-manager
 * --siteurl=... --server=...`) but any future npm methods added via the
 * ConnectionMethodRegistry filter auto-render as additional code blocks.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import CodeBlock from '../components/CodeBlock.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';

const Step12_NpmDetail = () => {
	const { state } = useWizardState();
	const bootstrap = window.acrossaiMcpQuickConnect || {};
	const npmMethods = state.methods.npm || [];

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

	if ( npmMethods.length === 0 ) {
		return (
			<Notice status="warning">
				{ __( 'No npm methods registered.', 'acrossai-mcp-manager' ) }
			</Notice>
		);
	}

	return (
		<div>
			<span
				style={ {
					display: 'block',
					fontSize: 11,
					fontWeight: 600,
					letterSpacing: '0.06em',
					textTransform: 'uppercase',
					color: '#757575',
					marginBottom: 8,
				} }
			>
				{ __( 'Command', 'acrossai-mcp-manager' ) }
			</span>

			{ npmMethods.map( ( m, idx ) => {
				// Prefer the DTO's `command` field; if absent, compose a default.
				const command =
					m.command ||
					`npx -y @acrossai/mcp-manager --siteurl=${ bootstrap.siteUrl } --server=${ server.slug }`;
				return (
					<CodeBlock key={ m.slug || idx } variant="inline">
						{ command }
					</CodeBlock>
				);
			} ) }

			<div
				style={ {
					display: 'flex',
					flexDirection: 'column',
					gap: 12,
					marginTop: 20,
					marginBottom: 20,
				} }
			>
				<div style={ { display: 'flex', alignItems: 'center', gap: 16 } }>
					<strong style={ { flex: '0 0 120px' } }>
						{ __( 'Site URL', 'acrossai-mcp-manager' ) }
					</strong>
					<code
						style={ {
							background: '#f0f0f0',
							padding: '3px 8px',
							borderRadius: 2,
							fontSize: 12,
						} }
					>
						{ bootstrap.siteUrl }
					</code>
				</div>
				<div style={ { display: 'flex', alignItems: 'center', gap: 16 } }>
					<strong style={ { flex: '0 0 120px' } }>
						{ __( 'Server', 'acrossai-mcp-manager' ) }
					</strong>
					<code
						style={ {
							background: '#f0f0f0',
							padding: '3px 8px',
							borderRadius: 2,
							fontSize: 12,
						} }
					>
						{ server.slug }
					</code>
				</div>
			</div>

			<p style={ { fontSize: 12, color: '#757575', lineHeight: 1.5 } }>
				{ __(
					'The CLI prompts for an Application Password on first run and stores it in your OS keychain.',
					'acrossai-mcp-manager'
				) }
			</p>
		</div>
	);
};

export default Step12_NpmDetail;
