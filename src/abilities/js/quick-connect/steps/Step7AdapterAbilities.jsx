/**
 * Feature 099 — screen 7: reaching abilities through MCP Adapter.
 *
 * The adapter path's last teaching screen (spec FR-011). Installing the adapter
 * is not the finish line: it exposes a default MCP server, and abilities still
 * have to be enabled against it before an assistant can call anything. Someone
 * who stops at "the adapter is active" has a connected site that does nothing,
 * with no error anywhere to explain why — which is exactly the gap this screen
 * closes.
 *
 * Reached two ways, and both matter: after the install instructions on screen 6,
 * or straight from the transport screen when the adapter was already active
 * (FR-011a). The copy therefore does not assume the operator just installed it.
 *
 * @package
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import VideoEmbed from '../components/VideoEmbed.jsx';
import Notice from '../components/Notice.jsx';
import useAdvanceGuard, { useFooterAction } from '../hooks/useAdvanceGuard.js';
import { WALKTHROUGH_VIDEO_ID, WALKTHROUGH_PLAYLIST } from '../videos.js';

/**
 * MCP Adapter enablement walkthrough.
 *
 * @return {Element} Screen content.
 */
const Step7AdapterAbilities = () => {
	// Purely informational — Continue is always available, and this is the last
	// screen before the summary, so StepLayout labels it "Finish".
	useAdvanceGuard(true);

	const bootstrap = window.acrossaiQuickConnect || {};

	// Abilities are enabled on the Abilities page, so that is where this screen
	// sends the operator — the same footer affordance screens 2 and 3 offer.
	// New tab, so the wizard survives the trip and they can come back and finish.
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
				{__(
					'Enable abilities for MCP Adapter',
					'acrossai-abilities-manager'
				)}
			</h2>

			<p className="qs__step-subtitle">
				{__(
					'MCP Adapter exposes a default server. Abilities reach an AI assistant once they are enabled against it.',
					'acrossai-abilities-manager'
				)}
			</p>

			{/*
			 * Still the shared placeholder recording — screens 2 and 3 have their
			 * own now, this one does not yet, so it keeps the playlist the
			 * placeholder belongs to. No autoPlay: DEC-ADMIN-EMBED-AUTOPLAY-EXCEPTION
			 * limits that to screens whose own recording is the point, and a
			 * stand-in shared with another screen is not that.
			 */}
			<VideoEmbed
				videoId={WALKTHROUGH_VIDEO_ID}
				playlist={WALKTHROUGH_PLAYLIST}
				title={__(
					'How to enable abilities for the MCP Adapter server',
					'acrossai-abilities-manager'
				)}
			/>

			<Notice status="info">
				{__(
					'Nothing is exposed by default. An assistant sees only the abilities you enable, so you can start with a few and widen access later.',
					'acrossai-abilities-manager'
				)}
			</Notice>
		</div>
	);
};

export default Step7AdapterAbilities;
