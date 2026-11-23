/**
 * Feature 099 — completion screen.
 *
 * Summarises what the site now has and offers onward destinations. There is no
 * "completed" flag to write: the wizard is re-runnable by design (spec
 * Assumptions), so finishing simply means the operator has somewhere to go next.
 *
 * @package
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import useWizardState from '../hooks/useWizardState.js';
import useWizardRouter from '../hooks/useWizardRouter.js';

/**
 * Name the transport the site is connected through.
 *
 * The recommended transport wins when both are present: it is the one this
 * wizard set up, and naming it is what the operator will recognise.
 *
 * Named export for unit testing (PATTERN-NAMED-EXPORT-JEST).
 *
 * @param {boolean} managerActive Whether the recommended transport is active.
 * @param {boolean} adapterActive Whether the alternative transport is active.
 * @return {string} Human-readable transport name.
 */
export const describeTransport = (managerActive, adapterActive) => {
	if (managerActive) {
		return __('AcrossAI MCP Manager', 'acrossai-abilities-manager');
	}

	if (adapterActive) {
		return __('MCP Adapter', 'acrossai-abilities-manager');
	}

	return __('Not connected yet', 'acrossai-abilities-manager');
};

/**
 * Completion screen.
 *
 * @return {Element} Screen content.
 */
const Completion = () => {
	const { state } = useWizardState();
	const router = useWizardRouter();

	const bootstrap = window.acrossaiQuickConnect || {};
	const total = state.abilities.total || 0;
	const groups = state.abilities.tabGroups || [];
	const managerActive = state.plugins.mcpManager === 'active';
	const adapterActive = state.plugins.mcpAdapter === 'active';
	const connected = managerActive || adapterActive;

	const transportLabel = describeTransport(managerActive, adapterActive);

	return (
		<div className="qs__completion">
			<div className="qs__gate-card">
				<h2 className="qs__step-title">
					{connected
						? __("You're all set", 'acrossai-abilities-manager')
						: __('Setup complete', 'acrossai-abilities-manager')}
				</h2>

				<dl className="qs__summary">
					<div>
						<dt>{__('Abilities', 'acrossai-abilities-manager')}</dt>
						<dd>
							{sprintf(
								/* translators: %d: number of abilities. */
								_n(
									'%d ability available',
									'%d abilities available',
									total,
									'acrossai-abilities-manager'
								),
								total
							)}
						</dd>
					</div>
					<div>
						<dt>
							{__('Integrations', 'acrossai-abilities-manager')}
						</dt>
						<dd>
							{sprintf(
								/* translators: %d: number of integration groups. */
								_n(
									'%d group',
									'%d groups',
									groups.length,
									'acrossai-abilities-manager'
								),
								groups.length
							)}
						</dd>
					</div>
					<div>
						<dt>
							{__('Connected via', 'acrossai-abilities-manager')}
						</dt>
						<dd>{transportLabel}</dd>
					</div>
				</dl>
			</div>

			{!connected && (
				<p className="qs__gate-copy qs__gate-copy--muted">
					{__(
						'No transport is active yet, so your abilities are not reachable by an AI assistant. You can re-run this setup at any time from the AcrossAI menu or the admin toolbar.',
						'acrossai-abilities-manager'
					)}
				</p>
			)}

			<div className="qs__gate-actions">
				<a className="qs-btn" href={bootstrap.adminUrl || '#'}>
					{__('Go to Abilities', 'acrossai-abilities-manager')}
				</a>
				<button
					type="button"
					className="qs-btn qs-btn--secondary"
					onClick={() => router.exit()}
				>
					{__('Exit setup', 'acrossai-abilities-manager')}
				</button>
			</div>

			<p className="qs__gate-copy qs__gate-copy--muted qs__completion-footnote">
				{__(
					'You can re-run this wizard any time from the admin toolbar.',
					'acrossai-abilities-manager'
				)}
			</p>
		</div>
	);
};

export default Completion;
