/**
 * Database settings tab — React root.
 *
 * Mounts on the div emitted by Database_Settings_Menu::render_root() and renders the access-policy
 * panels. Persists through acrossai/v1/database-settings/policy; reads the table list from
 * acrossai/v1/database-settings/tables.
 *
 * @package
 * @since   0.0.45
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import DatabaseSettings from './components/DatabaseSettings.jsx';

// Window global emitted by Database_Settings_Menu::enqueue_assets().
const {
	nonce = '',
	restBase = 'database-settings',
	restNamespace = 'acrossai/v1',
} = window.acrossaiDatabaseSettings || {};

if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const container = document.getElementById( 'acrossai-database-settings-root' );

if ( container ) {
	const root = createRoot( container );
	root.render(
		<DatabaseSettings restNamespace={ restNamespace } restBase={ restBase } />,
	);
}
