/**
 * Feature 099 — screen 4: what the abilities cover.
 *
 * Read-only by design (spec FR-018). Its job is to convey breadth before the
 * transport ask, not to be a second configuration surface — per-ability access
 * is set on the abilities list.
 *
 * Groups come from the server via the Library module's published filter, using
 * the shared labelling rule, so this screen and the toolset strip on the
 * abilities list cannot disagree (spec SC-004). Feature 102 retired the
 * Ability Integrations page these labels used to be shared with; the rule and
 * the filter are unchanged, so the guarantee still holds.
 *
 * @package
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';

/**
 * Integrations showcase screen.
 *
 * @return {Element} Screen content.
 */
const Step4Integrations = () => {
	const { state } = useWizardState();

	const groups = state.abilities.tabGroups || [];
	const total = state.abilities.total || 0;

	// Informational only — Continue is always available.
	useAdvanceGuard(true);

	return (
		<div>
			<h2 className="qs__step-title">
				{__('What your abilities cover', 'acrossai-abilities-manager')}
			</h2>

			<p className="qs__step-subtitle">
				{groups.length > 0
					? sprintf(
							/* translators: 1: number of abilities, 2: number of groups. */
							__(
								'%1$d abilities across %2$d areas of your site. Groups appear here only when the plugin they belong to is active.',
								'acrossai-abilities-manager'
							),
							total,
							groups.length
						)
					: __(
							'Integration groups appear here as abilities are registered.',
							'acrossai-abilities-manager'
						)}
			</p>

			{groups.length > 0 ? (
				<ul className="qs__gate-bullets qs__gate-bullets--grid">
					{groups.map((group) => (
						<li key={group.key}>
							<span className="qs__gate-group-label">
								{group.label}
							</span>{' '}
							<span className="qs__gate-group-count">
								{sprintf(
									/* translators: %d: number of abilities in this group. */
									_n(
										'%d ability',
										'%d abilities',
										group.count,
										'acrossai-abilities-manager'
									),
									group.count
								)}
							</span>
						</li>
					))}
				</ul>
			) : (
				<Notice status="info">
					{__(
						'No integration groups are registered yet. This is normal on a new site — groups appear as abilities are added, and optional integrations show up once their plugin is active.',
						'acrossai-abilities-manager'
					)}
				</Notice>
			)}
		</div>
	);
};

export default Step4Integrations;
