/**
 * Feature 099 — screen 5: connect a transport.
 *
 * The screen the whole wizard exists for. Abilities do nothing until something
 * exposes them to an AI client, and this is where that connection is made.
 *
 * Three states, all reachable:
 *
 * - Recommended transport missing → install it in place, then hand off to its
 *   own setup.
 * - Recommended transport already active → say so and offer to finish, rather
 *   than offering to install something that is already there (FR-025).
 * - Alternative transport chosen → Continue leads to its instructions, or
 *   straight to the enablement walkthrough when it is already present.
 *
 * @package
 */

import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import Notice from '../components/Notice.jsx';
import RadioCard from '../components/RadioCard.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useWizardRouter from '../hooks/useWizardRouter.js';
import useAdvanceGuard, {
	useFooterAction,
	useHideContinue,
} from '../hooks/useAdvanceGuard.js';

const MCP_MANAGER_SLUG = 'acrossai-mcp-manager';

/**
 * Decide whether the hand-off target is safe to navigate to.
 *
 * The URL arrives in a response body and is assigned to window.location, which
 * is one refactor away from an open redirect. Same-origin is asserted on the
 * client as well as being built with admin_url() on the server (SEC-003).
 *
 * Named export for unit testing (PATTERN-NAMED-EXPORT-JEST).
 *
 * @param {string} candidate Target URL from the API.
 * @param {string} origin    Current window origin.
 * @return {boolean} True when the target is same-origin.
 */
export const isSameOrigin = (candidate, origin) => {
	try {
		return new URL(candidate, origin).origin === origin;
	} catch {
		return false;
	}
};

/**
 * Label for the install action, given the current state.
 *
 * Extracted from the render body so the three-way choice reads as a decision
 * rather than a nested ternary.
 *
 * Named export for unit testing (PATTERN-NAMED-EXPORT-JEST).
 *
 * @param {boolean} working      Whether an install is in flight.
 * @param {string}  managerState missing | inactive | active.
 * @return {string} Button label.
 */
export const installActionLabel = (working, managerState) => {
	if (working) {
		return __('Installing…', 'acrossai-abilities-manager');
	}

	if (managerState === 'inactive') {
		return __(
			'Continue - Activate the plugin',
			'acrossai-abilities-manager'
		);
	}

	return __('Continue - Install the plugin', 'acrossai-abilities-manager');
};

/**
 * Whether the in-place install/activate action should be offered.
 *
 * Drives two things that must never disagree: the footer action itself, and
 * whether the shell's generic Continue is hidden. When they disagreed, the
 * screen showed two competing forward buttons and the generic one read
 * "Finish", inviting the operator to skip the connection step entirely.
 *
 * Named export for unit testing (PATTERN-NAMED-EXPORT-JEST).
 *
 * @param {boolean} managerActive Whether the recommended transport is active.
 * @param {string}  method        Currently selected transport.
 * @param {boolean} canInstall    Whether the caller holds the install capabilities.
 * @return {boolean} True when the install action belongs on this screen.
 */
export const shouldOfferInstall = (managerActive, method, canInstall) => {
	// Already connected: nothing to install.
	if (managerActive) {
		return false;
	}

	// The alternative transport cannot be installed from here (GitHub-only).
	if (method !== 'mcp-manager') {
		return false;
	}

	// SC-009: never offer an action the caller cannot perform.
	return !!canInstall;
};

/**
 * Transport selection screen.
 *
 * @return {Element} Screen content.
 */
const Step5ConnectTransport = () => {
	const { state, refetch } = useWizardState();
	const router = useWizardRouter();

	const [working, setWorking] = useState(false);
	const [error, setError] = useState(null);

	const managerState = state.plugins.mcpManager;
	const adapterState = state.plugins.mcpAdapter;
	// Elementor's server is not something this wizard installs — it arrives with Elementor and is
	// switched on at Elementor's own screen. Reported, not offered.
	const elementorState = state.plugins.elementorMcp;
	const elementorUrl = state.plugins.elementorMcpUrl;
	const managerActive = managerState === 'active';
	const canInstall = !!state.canInstall;

	// Continue is always available: choosing the alternative transport, or
	// finishing when already connected, are both legitimate ways off this screen.
	useAdvanceGuard(true);

	const handleInstall = useCallback(async () => {
		setWorking(true);
		setError(null);

		try {
			const bootstrap = window.acrossaiQuickConnect || {};
			const restRoot =
				bootstrap.restUrl || '/wp-json/acrossai/v1/quick-connect';

			await apiFetch({
				url: `${restRoot}/install-plugin`,
				method: 'POST',
				data: { slug: MCP_MANAGER_SLUG },
			});

			const fresh = await refetch();
			const target =
				fresh?.plugins?.mcpManagerWizardUrl ||
				state.plugins.mcpManagerWizardUrl;

			// Hand off to the transport's own setup so the operator continues in
			// one unbroken flow rather than landing back on a plugin list.
			if (target && isSameOrigin(target, window.location.origin)) {
				window.location.href = target;
				return;
			}

			// Same-origin check failed: stay put rather than navigating somewhere
			// unexpected. The install itself succeeded, so this is not an error
			// state for the operator — the next screen simply reflects reality.
			router.goTo('done');
		} catch (err) {
			setError(
				err?.message ||
					__(
						'Something went wrong. Try installing the plugin manually from Plugins → Add New.',
						'acrossai-abilities-manager'
					)
			);
		} finally {
			setWorking(false);
		}
	}, [refetch, router, state.plugins.mcpManagerWizardUrl]);

	const footerAction = useMemo(() => {
		if (!shouldOfferInstall(managerActive, router.method, canInstall)) {
			return null;
		}

		const label = installActionLabel(working, managerState);

		return {
			label,
			onClick: handleInstall,
			isLoading: working,
			disabled: working,
		};
	}, [
		managerActive,
		managerState,
		router.method,
		canInstall,
		working,
		handleInstall,
	]);

	useFooterAction(footerAction);

	// When the install action is present it is the only sensible way forward, so
	// the shell's own Continue is hidden. Showing both put two competing forward
	// buttons on one screen — and the generic one read "Finish", inviting the
	// operator to skip past the single step the wizard exists to complete.
	// Leaving is still possible via "Exit setup" in the header.
	useHideContinue(footerAction !== null);

	return (
		<div>
			<h2 className="qs__step-title">
				{__(
					'Connect your abilities to an AI assistant',
					'acrossai-abilities-manager'
				)}
			</h2>

			<p className="qs__step-subtitle">
				{__(
					'AcrossAI Abilities Manager works with AcrossAI MCP Manager, MCP Adapter and Elementor MCP. Pick how this site should connect.',
					'acrossai-abilities-manager'
				)}
			</p>

			{error && (
				<div style={{ marginBottom: 20 }}>
					<Notice status="error">{error}</Notice>
				</div>
			)}

			<div
				style={{
					display: 'grid',
					// Two columns. The recommended option (AcrossAI MCP Manager)
					// spans the full first row via `qs-card--full`; the remaining
					// options fall into the second row, one per column. With
					// Elementor absent there is a single remaining option, which
					// also spans full-width so it does not sit as a lone half.
					gridTemplateColumns: 'repeat(2, minmax(0, 1fr))',
					gap: 12,
				}}
				role="radiogroup"
				aria-label={__(
					'Connection method',
					'acrossai-abilities-manager'
				)}
			>
				<RadioCard
					name="qs-transport"
					value="mcp-manager"
					className="qs-card--full"
					selected={router.method === 'mcp-manager'}
					onSelect={() => router.setMethod('mcp-manager')}
					title={
						<>
							{__(
								'AcrossAI MCP Manager',
								'acrossai-abilities-manager'
							)}
							<span className="qs-card__badge">
								{__(
									'RECOMMENDED',
									'acrossai-abilities-manager'
								)}
							</span>
							{managerActive && (
								<span className="qs-card__badge qs-card__badge--inactive">
									{__(
										'Already active',
										'acrossai-abilities-manager'
									)}
								</span>
							)}
						</>
					}
					subtitle={__(
						'Installs in one click and walks you through connecting Claude, ChatGPT and others.',
						'acrossai-abilities-manager'
					)}
				/>

				<RadioCard
					name="qs-transport"
					value="mcp-adapter"
					className={
						'missing' === elementorState ? 'qs-card--full' : ''
					}
					selected={router.method === 'mcp-adapter'}
					onSelect={() => router.setMethod('mcp-adapter')}
					title={
						<>
							{__('MCP Adapter', 'acrossai-abilities-manager')}
							{adapterState === 'active' && (
								<span className="qs-card__badge qs-card__badge--inactive">
									{__(
										'Already active',
										'acrossai-abilities-manager'
									)}
								</span>
							)}
						</>
					}
					subtitle={__(
						"WordPress's own adapter. Installed manually from GitHub — we'll show you how.",
						'acrossai-abilities-manager'
					)}
				/>

				{/*
				 * Elementor's server, when this site has one.
				 *
				 * Offered as a choice rather than a notice because it is one: an operator can
				 * legitimately decide this is how the site connects. It differs from the other
				 * two in that there is nothing for this wizard to install — Elementor ships the
				 * server and owns its switch — so selecting it explains what to do next instead
				 * of doing it. Hidden entirely when Elementor is absent; offering a destination
				 * that does not exist is worse than offering two.
				 */}
				{'missing' !== elementorState && (
					<RadioCard
						name="qs-transport"
						value="elementor-mcp"
						selected={router.method === 'elementor-mcp'}
						onSelect={() => router.setMethod('elementor-mcp')}
						title={
							<>
								{__(
									'Elementor MCP',
									'acrossai-abilities-manager'
								)}
								{'active' === elementorState && (
									<span className="qs-card__badge qs-card__badge--inactive">
										{__(
											'Already active',
											'acrossai-abilities-manager'
										)}
									</span>
								)}
							</>
						}
						subtitle={__(
							"Elementor's own server. Your AcrossAI abilities ride along with it — nothing to install.",
							'acrossai-abilities-manager'
						)}
					/>
				)}
			</div>

			{/*
			 * Elementor's server, when this site has one.
			 *
			 * Deliberately a notice rather than a third card. The two above are choices the
			 * operator makes and this wizard can act on; Elementor's server is a fact about the
			 * site, switched on at Elementor's own screen. Rendering it as a selectable option
			 * would offer an install path that does not exist and a decision already made
			 * elsewhere.
			 */}
			{/*
			 * Shown on selection, not always.
			 *
			 * The earlier version rendered this whenever Elementor was present, which put a green
			 * "already reachable" banner under a screen where the operator had chosen something
			 * else — two destinations claiming success at once. Tied to the selection it answers
			 * one question: having picked Elementor, what now?
			 */}
			{'elementor-mcp' === router.method && (
				<div style={{ marginTop: 24 }}>
					<Notice
						status={
							'active' === elementorState ? 'success' : 'warning'
						}
					>
						{'active' === elementorState
							? __(
									"Elementor MCP is running, and your AcrossAI abilities are available through it. In your AI client, connect to this site's Elementor MCP server — or reconnect if it is already set up, so it picks up the AcrossAI tools. Then continue to finish.",
									'acrossai-abilities-manager'
								)
							: __(
									"Elementor MCP is not switched on yet. Turn it on at Elementor → Elementor MCP, then connect your AI client to this site's Elementor MCP server — or reconnect an existing connection so it picks up the AcrossAI tools.",
									'acrossai-abilities-manager'
								)}
						{!!elementorUrl && (
							<>
								{' '}
								<a href={elementorUrl}>
									{__(
										'Open Elementor MCP settings',
										'acrossai-abilities-manager'
									)}
								</a>
							</>
						)}
					</Notice>
				</div>
			)}

			{managerActive && 'mcp-manager' === router.method && (
				<div style={{ marginTop: 24 }}>
					<Notice status="success">
						{__(
							'AcrossAI MCP Manager is already active on this site, so your abilities are connected. Continue to finish.',
							'acrossai-abilities-manager'
						)}
					</Notice>
				</div>
			)}

			{!managerActive &&
				router.method === 'mcp-manager' &&
				!canInstall && (
					<div style={{ marginTop: 24 }}>
						<Notice status="warning">
							{__(
								'You do not have permission to install plugins on this site. Ask a site administrator to install AcrossAI MCP Manager, or choose MCP Adapter to see manual instructions.',
								'acrossai-abilities-manager'
							)}
						</Notice>
					</div>
				)}
		</div>
	);
};

export default Step5ConnectTransport;
