/**
 * Feature 099 — screen 1: what this site just gained.
 *
 * The first thing a new operator sees. Its only job is to make the scale of the
 * install concrete before anything is asked of them.
 *
 * @package
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';

/**
 * Abilities overview screen.
 *
 * @return {Element} Screen content.
 */
const Step1AbilitiesOverview = () => {
	const { state } = useWizardState();
	const total = state.abilities.total || 0;

	// Nothing to validate — Continue is always available here.
	useAdvanceGuard(true);

	const bootstrap = window.acrossaiQuickConnect || {};

	return (
		<div>
			<h2 className="qs__step-title">
				{__(
					'Your site just gained new abilities',
					'acrossai-abilities-manager'
				)}
			</h2>

			<div className="qs__gate-card">
				{bootstrap.logoUrl && (
					<img
						className="qs__gate-card__logo"
						src={bootstrap.logoUrl}
						alt=""
						aria-hidden="true"
					/>
				)}

				<div className="qs__gate-stat">
					<span className="qs__gate-stat-number">{total}</span>
					<span className="qs__gate-stat-label">
						{_n(
							'ability ready to use',
							'abilities ready to use',
							total,
							'acrossai-abilities-manager'
						)}
					</span>
				</div>

				<p className="qs__gate-copy">
					{total > 0
						? sprintf(
								/* translators: %d: number of abilities. */
								__(
									'%d actions an AI assistant can perform on this site — reading content, managing plugins, checking site health, and more.',
									'acrossai-abilities-manager'
								),
								total
							)
						: __(
								'No abilities are registered yet. You can still walk through this setup; abilities appear here once they are enabled.',
								'acrossai-abilities-manager'
							)}
				</p>

				<p className="qs__gate-copy qs__gate-copy--muted">
					{__(
						'This short setup shows you how to manage them, then connects them to an AI assistant.',
						'acrossai-abilities-manager'
					)}
				</p>
			</div>
		</div>
	);
};

export default Step1AbilitiesOverview;
