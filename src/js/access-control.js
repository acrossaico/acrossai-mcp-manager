/**
 * Feature 015 — Access Control tab React entry.
 *
 * Mounts the shared <AccessControlEditor> (extracted in F069 T032 so the
 * Quick Connect via AcrossAI wizard's Step 2 uses the same component) into the per-server
 * AccessControlTab. All UI (provider dropdown, role checkboxes, user
 * autocomplete search) is owned by the vendor component wrapped in
 * <AccessControlEditor>; this entry file just reads the mount-div's
 * data-* config, registers the REST nonce middleware, and mounts.
 *
 * Compiled to build/js/access-control.js by webpack.config.js. Enqueued by
 * admin/Main.php on the Access Control tab only.
 */

import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import AccessControlEditor from './access-control/AccessControlEditor.jsx';

// Bundle the vendor stylesheet with this entry. The vendor's AccessControl.js
// deliberately does NOT import its own SCSS so consumers can control CSS
// delivery via their own webpack.
import '../../vendor/wpboilerplate/wpb-access-control/js/AccessControl.scss';

( function () {
	const mount = document.getElementById( 'acrossai-mcp-ac-root' );
	if ( ! mount ) {
		return;
	}

	const config = window.acrossaiMcpAccessControl || {};
	if ( ! config.pluginSlug || ! config.resourceKey ) {
		mount.textContent =
			'Access Control cannot boot — missing pluginSlug or resourceKey.';
		return;
	}

	// Register the REST nonce middleware once so all vendor apiFetch calls
	// carry it. F069 continuity: this stays in the bootstrap file (not in
	// AccessControlEditor) because apiFetch.use is a global side-effect and
	// the wizard mounts the same component in a context that has already
	// wired createNonceMiddleware at src/js/quick-connect.js — double-wiring
	// would send two identical nonces on every request.
	if ( config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}

	render(
		<AccessControlEditor
			pluginSlug={ config.pluginSlug }
			namespace={ config.namespace }
			resourceKey={ config.resourceKey }
			restApiRoot={ config.restApiRoot }
			nonce={ config.nonce }
			title={ config.title }
			description={ config.description }
			saveLabel={ config.saveLabel }
		/>,
		mount
	);
} )();
