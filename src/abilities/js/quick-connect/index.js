/**
 * Quick Connect wizard — bundle entry (Feature 099).
 *
 * Mounts the wizard into the container printed by QuickConnectPage::render().
 * The bundle only loads when `?quick-connect=1` is present, so reaching this
 * file at all means the operator asked for the wizard.
 *
 * @package
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import App from './App.jsx';
import { WizardStateProvider } from './hooks/useWizardState.js';

const MOUNT_ID = 'acrossai-quick-connect-root';

/**
 * Resolve the wizard mount point.
 *
 * Exported for unit testing without rendering (PATTERN-NAMED-EXPORT-JEST).
 *
 * @return {HTMLElement|null} The mount element, or null when absent.
 */
export const getMountElement = () => document.getElementById( MOUNT_ID );

const bootstrap = window.acrossaiQuickConnect || {};

if ( bootstrap.restNonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.restNonce ) );
}

// Deliberately no createRootURLMiddleware: WP admin already sets
// wpApiSettings.root, and adding it produces a double-slash 404.

const mount = () => {
	const element = getMountElement();

	if ( ! element ) {
		return;
	}

	createRoot( element ).render(
		<WizardStateProvider>
			<App />
		</WizardStateProvider>,
	);
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
