/**
 * F069 — Step 6: Enable the server.
 *
 * Auto-skip case handled by App.jsx — when the server's DB `enabled` flag
 * is true (skips.skipEnable), App forwards step=6 → step=7 before this
 * component ever renders. So by the time we're here, the server is
 * definitively disabled.
 *
 * Footer layout: Back · Continue · Enable & Continue
 *   - Continue          → advance without enabling (server stays disabled;
 *                          user can enable later from the server list)
 *   - Enable & Continue → POST /step 6 enabled=true, refetch /state,
 *                          then App's auto-skip forwards past step 6
 *                          because state.servers[N].enabled just flipped
 *
 * No advance guard — Continue is always allowed. Enabling is optional; a
 * disabled server rejects every MCP request but that's a recoverable state.
 *
 * Visual pattern matches Step 4 / Step 8 — same "gate" grammar so all
 * server-lifecycle screens feel consistent (centered card, headline stat,
 * primary CTA in the footer, info notice below).
 *
 * @package AcrossAI_MCP_Manager
 */

import { useCallback, useMemo, useState, createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import { useFooterAction, useHideContinue } from '../hooks/useAdvanceGuard.js';

const MCP_ADAPTER_URL = 'https://github.com/wordpress/mcp-adapter';

const Step6_EnableServer = () => {
	const { saveStep } = useWizardState();
	const [ enabling, setEnabling ] = useState( false );

	const handleEnableAndContinue = useCallback( async () => {
		setEnabling( true );
		try {
			// apply_step_6 sets $refresh_servers = true, so the response
			// already includes the freshly-enabled server row. State reducer
			// merges it, App.jsx recomputes skips, skipEnable flips to true,
			// and the auto-skip effect forwards past step 6 to step 7.
			// No manual router.advance() here to avoid the double-jump
			// race that Step 5 hit.
			await saveStep( 6, { enabled: true } );
		} finally {
			setEnabling( false );
		}
	}, [ saveStep ] );

	const footerAction = useMemo(
		() => ( {
			label: __( 'Enable & Continue', 'acrossai-mcp-manager' ),
			onClick: handleEnableAndContinue,
			isLoading: enabling,
			disabled: enabling,
		} ),
		[ handleEnableAndContinue, enabling ]
	);
	useFooterAction( footerAction );

	// Hide the wizard's built-in Continue button on this step. The only
	// legitimate forward path is "Enable & Continue" — a disabled or
	// clickable-but-does-nothing Continue would just confuse users into
	// wondering why the wizard won't move. Back still works (they can
	// revise an earlier choice) and Enable & Continue is the primary CTA.
	useHideContinue( true );

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'Turn on your MCP endpoint', 'acrossai-mcp-manager' ) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'One more step. Your server needs to be enabled before it will accept any MCP requests.',
					'acrossai-mcp-manager'
				) }
			</p>

			<div className="qs__gate-card qs__gate-card--warning">
				<div className="qs__gate-stat">
					<span
						className="qs__gate-stat-icon"
						aria-hidden="true"
					>
						⏻
					</span>
					<span className="qs__gate-stat-label qs__gate-stat-label--warning">
						{ __(
							'Server is currently disabled',
							'acrossai-mcp-manager'
						) }
					</span>
				</div>

				<p className="qs__gate-copy">
					{ __(
						'While disabled, this server rejects every MCP request — even from administrators. Enable it to start accepting connections from your AI clients.',
						'acrossai-mcp-manager'
					) }
				</p>

				<p className="qs__gate-copy qs__gate-copy--muted">
					{ __(
						'You can disable this server at any time from the server list.',
						'acrossai-mcp-manager'
					) }
				</p>
			</div>

			<div style={ { marginTop: 24 } }>
				<Notice status="info">
					{ createInterpolateElement(
						__(
							'AcrossAI MCP Manager runs on top of the <a>MCP Adapter</a> framework and adds an admin safety layer: every server starts disabled so a half-configured server can never accidentally accept requests. Enable this one once you\'ve reviewed its access rules and abilities.',
							'acrossai-mcp-manager'
						),
						{
							a: (
								// eslint-disable-next-line jsx-a11y/anchor-has-content
								<a
									href={ MCP_ADAPTER_URL }
									target="_blank"
									rel="noopener noreferrer"
								/>
							),
						}
					) }
				</Notice>
			</div>
		</div>
	);
};

export default Step6_EnableServer;
