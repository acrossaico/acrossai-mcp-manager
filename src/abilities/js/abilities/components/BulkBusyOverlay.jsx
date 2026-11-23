/**
 * Shared full-screen busy overlay — Feature 056 FR-019.
 *
 * Renders a WP-native `.spinner is-active` scaled up (48×48) plus a status
 * label, over a blurred backdrop that covers the whole viewport. Consumers
 * mount conditionally on their own busy flag and pair with `document.body`
 * scroll-lock via useEffect. Extracted from AbilitiesList.jsx +
 * UserAccessBulkModal.jsx duplication (Constitution §VI DRY).
 *
 * @since 0.0.15
 */

/**
 * BulkBusyOverlay.
 *
 * @param {Object} props
 * @param {string} props.label Accessible status label — also the visible text.
 * @return {import('react').ReactElement} The overlay.
 */
export default function BulkBusyOverlay({ label }) {
	return (
		<div
			className="acrossai-abilities-bulk-busy-overlay"
			role="status"
			aria-live="polite"
			aria-label={label}
		>
			<span className="spinner is-active acrossai-abilities-bulk-busy-overlay__spinner" />
			<div className="acrossai-abilities-bulk-busy-overlay__label">
				{label}
			</div>
		</div>
	);
}
