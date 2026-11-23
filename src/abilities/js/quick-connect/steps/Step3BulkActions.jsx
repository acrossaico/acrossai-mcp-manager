/**
 * Feature 099 — screen 3: acting on many abilities at once.
 *
 * With several hundred abilities on a typical site, editing them one at a time
 * is impractical. This screen exists so operators know bulk actions are there
 * before they go looking.
 *
 * @package
 */

import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import VideoEmbed from '../components/VideoEmbed.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard, { useFooterAction } from '../hooks/useAdvanceGuard.js';
import { BULK_ACTIONS_VIDEO_ID } from '../videos.js';

/**
 * Bulk-actions walkthrough screen.
 *
 * @return {Element} Screen content.
 */
const Step3BulkActions = () => {
	const { state } = useWizardState();
	const total = state.abilities.total || 0;

	// Purely informational — Continue is always available.
	useAdvanceGuard(true);

	const bootstrap = window.acrossaiQuickConnect || {};

	// Same footer action as screen 2: bulk actions live on the Abilities page, so
	// the operator needs a route there to try what the recording just showed.
	// Lifted into the shell's footer so it joins the single Back / … / Continue
	// row rather than forming a competing one above it.
	//
	// Opens in a new tab on purpose — navigating away here would drop the
	// operator out of the wizard mid-flow. href rather than onClick +
	// window.open, which popup blockers kill silently, leaving a dead button.
	//
	// useFooterAction compares content rather than identity, so a literal here
	// would be safe; memoised anyway to avoid rebuilding the object each render.
	const footerAction = useMemo(
		() => ({
			label: __('Open Abilities page ↗', 'acrossai-abilities-manager'),
			href:
				bootstrap.adminUrl ||
				'admin.php?page=acrossai-abilities-manager',
			target: '_blank',
			variant: 'secondary',
		}),
		[bootstrap.adminUrl]
	);

	useFooterAction(footerAction);

	return (
		<div>
			<h2 className="qs__step-title">
				{__('Change many at once', 'acrossai-abilities-manager')}
			</h2>

			<p className="qs__step-subtitle">
				{total > 0
					? sprintf(
							/* translators: %d: number of abilities on the site. */
							__(
								'Enabling %d abilities one by one would take a while. Select a group and act on all of them together.',
								'acrossai-abilities-manager'
							),
							total
						)
					: __(
							'Select a group of abilities and enable, disable, or reset all of them together.',
							'acrossai-abilities-manager'
						)}
			</p>

			{/*
			 * No playlist: this screen has its own purpose-made recording now, so
			 * there is nothing to queue after it. It previously shared the
			 * placeholder walkthrough, which did carry one.
			 */}
			<VideoEmbed
				videoId={BULK_ACTIONS_VIDEO_ID}
				title={__(
					'How to use bulk actions',
					'acrossai-abilities-manager'
				)}
				autoPlay
			/>

			<Notice status="info">
				{__(
					'Bulk actions apply only to the abilities currently in view, so filter first and the selection stays predictable.',
					'acrossai-abilities-manager'
				)}
			</Notice>
		</div>
	);
};

export default Step3BulkActions;
