/**
 * Integrations tab — React root.
 *
 * Mounts on the div emitted by Integrations_Menu::render_root() and renders the
 * integration directory. Reads from acrossai/v1/integrations, which joins the catalogue on
 * acrossai.co onto this site's own integration state; writes one row at a time to
 * acrossai/v1/integrations/{slug}.
 *
 * @package
 * @since   0.0.42
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import IntegrationsDirectory from './components/IntegrationsDirectory.jsx';

// Window global emitted by Integrations_Menu::enqueue_assets().
const {
	nonce = '',
	restBase = 'integrations',
	restNamespace = 'acrossai/v1',
	proUrl = 'https://acrossai.co/pricing/',
	requestUrl = 'https://acrossai.co/request-integration',
	abilitiesUrl = '',
} = window.acrossaiIntegrations || {};

if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const container = document.getElementById( 'acrossai-integrations-root' );

if ( container ) {
	createRoot( container ).render(
		<IntegrationsDirectory
			restNamespace={ restNamespace }
			restBase={ restBase }
			proUrl={ proUrl }
			requestUrl={ requestUrl }
			abilitiesUrl={ abilitiesUrl }
		/>,
	);
}
