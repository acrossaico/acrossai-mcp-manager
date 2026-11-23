/**
 * File Manager settings tab — React root.
 *
 * Mounts on the div emitted by File_Manager_Settings_Menu::render_root()
 * and orchestrates three settings panels (write allowlist, read allowlist,
 * secret redaction). Persists changes via three REST endpoints under
 * acrossai/v1/file-manager-settings/*.
 *
 * @package
 * @since   0.1.0
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import FileManagerSettings from './components/FileManagerSettings.jsx';

// Nonce middleware — window global emitted by File_Manager_Settings_Menu::enqueue_assets().
const { nonce = '', restBase = 'file-manager-settings', restNamespace = 'acrossai/v1' } =
	window.acrossaiFileManagerSettings || {};

if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const container = document.getElementById( 'acrossai-file-manager-settings-root' );

if ( container ) {
	const root = createRoot( container );
	root.render(
		<FileManagerSettings
			restNamespace={ restNamespace }
			restBase={ restBase }
		/>,
	);
}
