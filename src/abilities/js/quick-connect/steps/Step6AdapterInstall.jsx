/**
 * Feature 099 — screen 6: installing the MCP Adapter.
 *
 * The adapter is distributed from GitHub, not the WordPress plugin directory,
 * so it cannot be installed in place the way MCP Manager is (spec FR-026). This
 * screen therefore does the next best thing: hands over a direct download link
 * and walks the four manual steps, then detects the result so nobody has to
 * guess whether it worked.
 *
 * Researched against the repository rather than assumed:
 * - Each release publishes a ready-to-install `mcp-adapter.zip` asset, and the
 *   archive's top-level folder is `mcp-adapter` — the folder name WordPress
 *   needs. The source zipball is NOT used: it unpacks to
 *   `WordPress-mcp-adapter-<sha>`, which installs under the wrong folder name
 *   and breaks both detection and future updates.
 * - Step 1 sends the operator to the release page rather than firing the
 *   download itself. A direct link to the asset starts a file download the
 *   moment it is clicked, with nothing on screen confirming what arrived or
 *   where. Landing on the release page keeps the version, the changelog and
 *   the assets list visible, so choosing `mcp-adapter.zip` over the source
 *   archive is a decision the operator can actually see themselves making —
 *   which matters, because picking the wrong one silently breaks detection.
 * - The link points at GitHub's permanent `releases/latest` alias, so it keeps
 *   working as the adapter releases.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard, { useFooterAction } from '../hooks/useAdvanceGuard.js';

/**
 * Minimum environment the adapter declares in its plugin header.
 *
 * @type {Object<string, string>}
 */
export const ADAPTER_REQUIREMENTS = {
	wordpress: '6.9',
	php: '7.4',
};

/**
 * MCP Adapter installation screen.
 *
 * @return {Element} Screen content.
 */
const Step6AdapterInstall = () => {
	const { state, refetch, isLoading } = useWizardState();
	const bootstrap = window.acrossaiQuickConnect || {};

	const detected = state.plugins.mcpAdapter === 'active';
	const installedNotActive = state.plugins.mcpAdapter === 'inactive';

	// Continue unlocks only once the adapter is actually running. Until then the
	// next screen — how to reach abilities through it — would be premature.
	useAdvanceGuard(detected);

	useFooterAction(
		detected
			? null
			: {
					label: isLoading
						? __('Checking…', 'acrossai-abilities-manager')
						: __(
								"I've installed it — check again",
								'acrossai-abilities-manager'
							),
					onClick: () => refetch(),
					isLoading,
					disabled: isLoading,
				}
	);

	return (
		<div>
			<h2 className="qs__step-title">
				{__('Install MCP Adapter', 'acrossai-abilities-manager')}
			</h2>

			<p className="qs__step-subtitle">
				{__(
					'MCP Adapter is published on GitHub rather than the WordPress plugin directory, so it takes four short manual steps.',
					'acrossai-abilities-manager'
				)}
			</p>

			{detected && (
				<div style={{ marginBottom: 20 }}>
					<Notice status="success">
						{__(
							'MCP Adapter is active on this site. Continue to see how to reach your abilities through it.',
							'acrossai-abilities-manager'
						)}
					</Notice>
				</div>
			)}

			{installedNotActive && (
				<div style={{ marginBottom: 20 }}>
					<Notice status="warning">
						{__(
							'MCP Adapter is installed but not activated yet. Finish step 4 below, then check again.',
							'acrossai-abilities-manager'
						)}
					</Notice>
				</div>
			)}

			<ol className="qs__steps">
				<li>
					<strong>
						{__(
							'Download the plugin',
							'acrossai-abilities-manager'
						)}
					</strong>
					<p>
						{__(
							'Open the latest release on GitHub. This link always points at the newest version:',
							'acrossai-abilities-manager'
						)}
					</p>
					<p>
						<a
							className="qs-btn qs-btn--secondary"
							href={
								bootstrap.mcpAdapterReleasesUrl ||
								'https://github.com/WordPress/mcp-adapter/releases/latest'
							}
							target="_blank"
							rel="noopener noreferrer"
						>
							{__(
								'Open the latest release ↗',
								'acrossai-abilities-manager'
							)}
						</a>
					</p>
					<p className="qs__step-hint">
						<code>
							{bootstrap.mcpAdapterReleasesUrl ||
								'https://github.com/WordPress/mcp-adapter/releases/latest'}
						</code>
					</p>
					<p className="qs__step-hint">
						{__(
							'On that page, scroll to "Assets" and choose mcp-adapter.zip to download it. Do not take the "Source code" archive — it unpacks under a different folder name and will not be recognised.',
							'acrossai-abilities-manager'
						)}
					</p>
				</li>

				<li>
					<strong>
						{__('Open Add Plugin', 'acrossai-abilities-manager')}
					</strong>
					<p>
						{__(
							'In WordPress, go to Plugins → Add Plugin.',
							'acrossai-abilities-manager'
						)}
					</p>
					<p>
						<a
							className="qs-btn qs-btn--secondary"
							href={
								bootstrap.pluginUploadUrl ||
								'plugin-install.php?tab=upload'
							}
							target="_blank"
							rel="noopener noreferrer"
						>
							{__(
								'Open Add Plugin ↗',
								'acrossai-abilities-manager'
							)}
						</a>
					</p>
				</li>

				<li>
					<strong>
						{__('Upload the plugin', 'acrossai-abilities-manager')}
					</strong>
					<p>
						{__(
							'Choose "Upload Plugin", select the mcp-adapter.zip you downloaded, then choose "Install Now".',
							'acrossai-abilities-manager'
						)}
					</p>
				</li>

				<li>
					<strong>
						{__('Activate it', 'acrossai-abilities-manager')}
					</strong>
					<p>
						{__(
							'Choose "Activate Plugin" on the screen that follows, or activate MCP Adapter from your Plugins list.',
							'acrossai-abilities-manager'
						)}
					</p>
					<p>
						<a
							className="qs-btn qs-btn--secondary"
							href={bootstrap.pluginsListUrl || 'plugins.php'}
							target="_blank"
							rel="noopener noreferrer"
						>
							{__('Open Plugins ↗', 'acrossai-abilities-manager')}
						</a>
					</p>
				</li>
			</ol>

			{!detected && (
				<Notice status="info">
					{__(
						'Come back to this tab afterwards and choose "I\'ve installed it — check again". Continue unlocks once the adapter is detected.',
						'acrossai-abilities-manager'
					)}
				</Notice>
			)}

			<p className="qs__step-hint">
				{__(
					'MCP Adapter requires WordPress 6.9 or newer and PHP 7.4 or newer.',
					'acrossai-abilities-manager'
				)}
			</p>
		</div>
	);
};

export default Step6AdapterInstall;
