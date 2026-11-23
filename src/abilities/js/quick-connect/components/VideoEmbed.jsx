/**
 * Feature 099 — walkthrough player.
 *
 * Click-to-load by default. Nothing is requested from the video host until the
 * operator presses play, per `DEC-ADMIN-THIRD-PARTY-EMBED` (Active) and security
 * finding SEC-001: an auto-loading iframe in an authenticated admin screen
 * discloses the admin's IP, user agent, and a Referer revealing the site's
 * origin — before any user action, with no consent step.
 *
 * `autoPlay` opts a screen out of that facade, on product instruction, so the
 * recording starts on its own. Understand what it costs before adding it to
 * another screen: the contact with the video host now happens on page load
 * rather than on a deliberate press, which is the disclosure SEC-001 is about.
 * It is defensible on a screen whose entire purpose is the recording; it is not
 * a default worth spreading. Playback is muted because browsers block audible
 * autoplay outright — a muted start is the only kind that actually plays.
 *
 * Either way the fallback holds: when the embed is blocked by connectivity, a
 * privacy tool, or a regional restriction, the screen still explains itself and
 * offers a working external link (FR-019a).
 *
 * SECURITY: no dangerouslySetInnerHTML anywhere in this tree.
 *
 * @package
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Privacy-preserving embed host.
 *
 * youtube-nocookie.com does not set tracking cookies until playback begins.
 *
 * @type {string}
 */
const EMBED_HOST = 'https://www.youtube-nocookie.com/embed/';

/**
 * Public watch host, used for the always-visible fallback link.
 *
 * @type {string}
 */
const WATCH_HOST = 'https://www.youtube.com/watch';

/**
 * Build the embed URL for a video.
 *
 * Named export so URL construction is unit-testable without rendering
 * (PATTERN-NAMED-EXPORT-JEST).
 *
 * @param {string} videoId  Video identifier.
 * @param {string} playlist Optional playlist identifier.
 * @return {string} Embed URL on the privacy host.
 */
export const buildEmbedUrl = (videoId, playlist = '') => {
	const params = new URLSearchParams({ rel: '0' });

	if (playlist) {
		params.set('list', playlist);
	}

	// autoplay=1 covers both entry paths: behind the facade it simply honours the
	// click the operator just made, and on an autoPlay screen it is the start
	// itself. mute=1 is not optional in either case — browsers refuse to start
	// audible playback without a gesture, so an unmuted autoplay is a still
	// frame.
	params.set('autoplay', '1');
	params.set('mute', '1');

	return `${EMBED_HOST}${encodeURIComponent(videoId)}?${params.toString()}`;
};

/**
 * Build the external watch URL for the fallback link.
 *
 * @param {string} videoId  Video identifier.
 * @param {string} playlist Optional playlist identifier.
 * @return {string} Public watch URL.
 */
export const buildWatchUrl = (videoId, playlist = '') => {
	const params = new URLSearchParams({ v: videoId });

	if (playlist) {
		params.set('list', playlist);
	}

	return `${WATCH_HOST}?${params.toString()}`;
};

/**
 * Responsive 16:9 walkthrough player, click-to-load unless told otherwise.
 *
 * @param {Object}  props          Component props.
 * @param {string}  props.videoId  Video identifier.
 * @param {string}  props.playlist Optional playlist identifier.
 * @param {string}  props.title    Accessible title for the player.
 * @param {boolean} props.autoPlay Skip the facade and start muted on load.
 * @return {Element} The player.
 */
const VideoEmbed = ({ videoId, playlist = '', title, autoPlay = false }) => {
	// Seeded from the prop rather than toggled by an effect: an autoPlay screen
	// has the iframe in its very first render, so there is no facade flash.
	const [loaded, setLoaded] = useState(autoPlay);

	const watchUrl = buildWatchUrl(videoId, playlist);
	const playLabel = sprintf(
		/* translators: %s: walkthrough title. */
		__('Play the walkthrough: %s', 'acrossai-abilities-manager'),
		title
	);

	return (
		<div className="qs__pro-card qs__pro-card--video">
			<div className="qs__pro-card__video">
				{loaded ? (
					<iframe
						src={buildEmbedUrl(videoId, playlist)}
						title={title}
						// Lazy loading defers the request until the frame nears
						// the viewport, which would stall an autoplay start.
						loading={autoPlay ? 'eager' : 'lazy'}
						/*
						 * strict-origin-when-cross-origin, NOT no-referrer.
						 *
						 * no-referrer was tried first and YouTube rejected the
						 * embed outright with "Video player configuration error
						 * (Error 153)" — it needs a Referer to verify which
						 * origin is allowed to embed the video, and with none it
						 * refuses to play.
						 *
						 * This policy still addresses the actual finding in
						 * SEC-001: it sends only the origin
						 * (https://example.com), never the full admin URL with
						 * its page and query string. The admin path stays
						 * private; the bare hostname is what YouTube needs.
						 */
						referrerPolicy="strict-origin-when-cross-origin"
						allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
						allowFullScreen
					/>
				) : (
					<button
						type="button"
						className="qs__video-facade"
						onClick={() => setLoaded(true)}
						aria-label={playLabel}
					>
						<span
							className="qs__video-facade__play"
							aria-hidden="true"
						/>
						<span className="qs__video-facade__label">
							{__(
								'Play walkthrough',
								'acrossai-abilities-manager'
							)}
						</span>
					</button>
				)}
			</div>

			<p className="qs__video-fallback">
				<a href={watchUrl} target="_blank" rel="noopener noreferrer">
					{__('Watch on YouTube ↗', 'acrossai-abilities-manager')}
				</a>
			</p>
		</div>
	);
};

export default VideoEmbed;
