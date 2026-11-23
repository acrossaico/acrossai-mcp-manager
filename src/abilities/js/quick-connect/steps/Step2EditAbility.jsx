/**
 * Feature 099 — screen 2: editing a single ability.
 *
 * Teaches that abilities are configurable before the operator is asked to
 * connect anything. Most people never discover the edit screen on their own.
 *
 * @package
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import VideoEmbed from '../components/VideoEmbed.jsx';
import Notice from '../components/Notice.jsx';
import useAdvanceGuard, { useFooterAction } from '../hooks/useAdvanceGuard.js';
import { EDIT_ABILITY_VIDEO_ID } from '../videos.js';

/**
 * Editing walkthrough screen.
 *
 * @return {Element} Screen content.
 */
const Step2EditAbility = () => {
	// Purely informational — Continue is always available.
	useAdvanceGuard(true);

	const bootstrap = window.acrossaiQuickConnect || {};

	// Lifted into the shell's footer so it shares the one button row rather than
	// forming a second, competing one above it.
	//
	// Opens in a new tab on purpose: navigating away in this tab would drop the
	// operator out of the wizard mid-flow, so the walkthrough stays put while
	// they go and try it. href rather than onClick + window.open — popup
	// blockers silently kill the latter, leaving a dead button.
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
				{__('Every ability is editable', 'acrossai-abilities-manager')}
			</h2>

			<p className="qs__step-subtitle">
				{__(
					'Rename an ability, rewrite its description, or restrict who can run it — without touching code.',
					'acrossai-abilities-manager'
				)}
			</p>

			{/*
			 * No playlist: this screen has its own purpose-made recording, so
			 * there is nothing to queue up after it.
			 */}
			<VideoEmbed
				videoId={EDIT_ABILITY_VIDEO_ID}
				title={__(
					'How to edit an ability',
					'acrossai-abilities-manager'
				)}
				autoPlay
			/>

			<Notice status="info">
				{__(
					'Your edits are stored as overrides, so they survive plugin updates and can be reverted at any time.',
					'acrossai-abilities-manager'
				)}
			</Notice>
		</div>
	);
};

export default Step2EditAbility;
