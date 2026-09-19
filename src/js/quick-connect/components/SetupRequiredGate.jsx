/**
 * The handover gate — why no client configuration is shown yet.
 *
 * The wizard deliberately lets an operator select, create and ENABLE a server
 * whose type needs a plugin that is not active: refusing to enable would
 * strand the wizard for exactly the person who has not installed the add-on
 * yet, which is most people running it.
 *
 * Handing over a configuration is the step that must still wait. Such a server
 * answers with a single `acrossai/setup-required` tool, so a config pasted into
 * Claude or Cursor now would connect and look broken — and because clients
 * cache their tool list at connect time, it would keep looking broken after the
 * plugin was installed. Better to say so here than to hand over something that
 * fails later somewhere we cannot explain it.
 *
 * Renders NOTHING when the server is ready, so a step can mount it
 * unconditionally and stay readable.
 *
 * @package AcrossAI_MCP_Manager
 */

import { __ } from '@wordpress/i18n';
import Notice from './Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';

const SetupRequiredGate = () => {
	const { state } = useWizardState();
	const setup = state.setupRequired;

	if ( ! setup || ! setup.message ) {
		return null;
	}

	return (
		<Notice status="warning">
			<p>
				<strong>
					{ __(
						'Connection details are not ready yet.',
						'acrossai-mcp-manager'
					) }
				</strong>
			</p>
			<p>{ setup.message }</p>
			<p>
				{ __(
					'Install and activate it, then return to this step — the server is already enabled, so nothing else needs changing.',
					'acrossai-mcp-manager'
				) }
			</p>
		</Notice>
	);
};

export default SetupRequiredGate;
