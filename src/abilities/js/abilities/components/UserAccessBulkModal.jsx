/**
 * Bulk User Access modal — Feature 056.
 *
 * Mounts the composer's <AccessControl> component (same one AbilityForm.jsx
 * uses per-row) with the first selected slug as its resourceKey so the picker
 * pre-fills with a real, current rule the operator can see. On Apply, the
 * captured (ac_key, ac_options) tuple is written to EVERY selected slug via
 * the bulkSetUserAccessRule store thunk.
 *
 * Modal chrome is hand-rolled HTML/SCSS to avoid a package.json change
 * (spec §Out-of-Scope forbids new npm deps; the composer AC component
 * is already an accepted external import in AbilityForm.jsx).
 *
 * @since 0.0.15
 */
import { useState, useCallback, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { AccessControl } from '@wpb/access-control';
import { STORE_NAME } from '../store/index';
import BulkBusyOverlay from './BulkBusyOverlay';

const abilitiesConfig = window.acrossaiAbilitiesManager || {};

/**
 * UserAccessBulkModal.
 *
 * @param {Object}   props
 * @param {string[]} props.slugs     Selected ability slugs (min length 1).
 * @param {Function} props.onClose   Called when Cancel or backdrop dismisses the modal.
 * @param {Function} props.onApplied Called after all per-slug writes resolve.
 * @return {import('react').ReactElement|null} The modal.
 */
export default function UserAccessBulkModal({ slugs, onClose, onApplied }) {
	const [acState, setAcState] = useState(null); // { key, options } captured from <AccessControl>
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState(null);
	const dispatch = useDispatch(STORE_NAME);

	const handleAcChange = useCallback((key, options) => {
		setAcState({ key, options });
	}, []);

	// Close modal on Escape key (but not while a write is in flight).
	useEffect(() => {
		function onKey(e) {
			if ('Escape' === e.key && !busy) {
				onClose();
			}
		}
		document.addEventListener('keydown', onKey);
		return () => document.removeEventListener('keydown', onKey);
	}, [busy, onClose]);

	// Lock body scroll while modal is mounted (and again re-locks while busy).
	useEffect(() => {
		const prevOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		return () => {
			document.body.style.overflow = prevOverflow;
		};
	}, []);

	async function handleApply() {
		if (!acState) {
			return;
		}
		setError(null);
		setBusy(true);
		try {
			await dispatch.bulkSetUserAccessRule(
				slugs,
				acState.key,
				acState.options
			);
			onApplied();
		} catch (e) {
			setError(
				e?.message ||
					__(
						'Failed to apply the User Access rule.',
						'acrossai-abilities-manager'
					)
			);
			setBusy(false);
		}
	}

	const acAvailable =
		abilitiesConfig.access_control_available &&
		abilitiesConfig.access_control_slug;
	const previewSlug = slugs[0];
	const title = acAvailable
		? sprintf(
				/* translators: %d: count of selected abilities */
				__(
					'Set User Access on %d abilities',
					'acrossai-abilities-manager'
				),
				slugs.length
			)
		: __('User Access is unavailable', 'acrossai-abilities-manager');

	return (
		<div
			className="acrossai-abilities-user-access-bulk-modal__backdrop"
			role="presentation"
			onClick={(e) => {
				if (e.target === e.currentTarget && !busy) {
					onClose();
				}
			}}
			onKeyDown={() => {}}
		>
			<div
				className="acrossai-abilities-user-access-bulk-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="acrossai-abilities-user-access-bulk-modal__title"
			>
				<div className="acrossai-abilities-user-access-bulk-modal__header">
					<h2 id="acrossai-abilities-user-access-bulk-modal__title">
						{title}
					</h2>
					<button
						type="button"
						className="acrossai-abilities-user-access-bulk-modal__close"
						aria-label={__('Close', 'acrossai-abilities-manager')}
						onClick={onClose}
						disabled={busy}
					>
						×
					</button>
				</div>
				<div className="acrossai-abilities-user-access-bulk-modal__body">
					{!acAvailable && (
						<div className="notice notice-warning inline-notice">
							<p>
								{__(
									'The wpb-access-control library is not loaded on this site, so bulk User Access rules cannot be applied.',
									'acrossai-abilities-manager'
								)}
							</p>
						</div>
					)}
					{acAvailable && (
						<>
							<div className="notice notice-info inline-notice">
								<p>
									{sprintf(
										/* translators: 1: preview slug used to seed the picker, 2: selected count */
										__(
											'The rule below is pre-filled from "%1$s" (the first selected ability). Clicking Apply will overwrite the User Access rule on all %2$d selected abilities.',
											'acrossai-abilities-manager'
										),
										previewSlug,
										slugs.length
									)}
								</p>
							</div>
							<div className="acrossai-abilities-user-access-bulk-modal__ac">
								<AccessControl
									pluginSlug={
										abilitiesConfig.access_control_slug
									}
									namespace="acrossai-abilities"
									resourceKey={previewSlug}
									restApiRoot={
										abilitiesConfig.rest_url || '/wp-json'
									}
									nonce={abilitiesConfig.nonce || ''}
									hideHeader={true}
									hideSaveButton={true}
									onChange={handleAcChange}
								/>
							</div>
							{error && (
								<div
									className="notice notice-error inline-notice"
									role="alert"
								>
									<p>{error}</p>
								</div>
							)}
						</>
					)}
				</div>
				<div className="acrossai-abilities-user-access-bulk-modal__footer">
					<button
						type="button"
						className="button"
						onClick={onClose}
						disabled={busy}
					>
						{__('Cancel', 'acrossai-abilities-manager')}
					</button>
					{acAvailable && (
						<button
							type="button"
							className="button button-primary"
							onClick={handleApply}
							disabled={busy || !acState}
						>
							{busy
								? __('Applying…', 'acrossai-abilities-manager')
								: sprintf(
										/* translators: %d: count of selected abilities */
										__(
											'Apply to %d abilities',
											'acrossai-abilities-manager'
										),
										slugs.length
									)}
						</button>
					)}
				</div>
			</div>
			{busy && (
				<BulkBusyOverlay
					label={__(
						'Applying User Access rule…',
						'acrossai-abilities-manager'
					)}
				/>
			)}
		</div>
	);
}
