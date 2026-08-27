/**
 * F069 — Quick Connect via AcrossAI wizard React entry.
 *
 * Feature 069 T025 + T026 — Mounts <WizardStateProvider><App /></WizardStateProvider>
 * at #acrossai-mcp-quick-connect-root (rendered by QuickConnectPage::render()).
 *
 * TASK-SEC-005 / SEC-005 — apiFetch middleware wiring:
 *   - Wire `createNonceMiddleware(bootstrap.restNonce)` so every REST call
 *     from the wizard carries a valid X-WP-Nonce.
 *   - Do NOT wire `createRootURLMiddleware` — WordPress admin already sets
 *     `wpApiSettings.root`; adding it risks the double-slash 404 (B25).
 *     Matches F017 abilities.js:95 pattern.
 *   - Long-open-tab 403 case: useWizardState.normalizeError() maps 403
 *     responses to the user-friendly "Your session has expired..." message
 *     — no additional entry-file wiring needed.
 *
 * @package AcrossAI_MCP_Manager
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import './quick-connect/App.jsx';
import '../scss/quick-connect.scss';

// Vendor Access Control stylesheet — required by <AccessControlEditor>
// (mounted from Step 2). The vendor package deliberately does NOT import
// its own SCSS so consumers control CSS delivery; mirror what
// src/js/access-control.js does for the per-server-edit tab.
import '../../vendor/wpboilerplate/wpb-access-control/js/AccessControl.scss';

import App from './quick-connect/App.jsx';
import { WizardStateProvider } from './quick-connect/hooks/useWizardState.js';

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap payload — set by wp_localize_script() in admin/Main.php T018.
// ─────────────────────────────────────────────────────────────────────────

const bootstrap = window.acrossaiMcpQuickConnect || {};

// ─────────────────────────────────────────────────────────────────────────
// TASK-SEC-005 — apiFetch nonce middleware.
// Wired ONCE at entry-file scope so every apiFetch() call from any wizard
// component carries the X-WP-Nonce header automatically.
// ─────────────────────────────────────────────────────────────────────────

if ( bootstrap.restNonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.restNonce ) );
}

// ─────────────────────────────────────────────────────────────────────────
// Mount
// ─────────────────────────────────────────────────────────────────────────

const mount = () => {
	const el = document.getElementById( 'acrossai-mcp-quick-connect-root' );
	if ( ! el ) {
		return;
	}
	const root = createRoot( el );
	root.render(
		<WizardStateProvider>
			<App />
		</WizardStateProvider>
	);
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
