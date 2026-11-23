/**
 * Entry point for the Custom Abilities Manager React app.
 *
 * @since 0.2.0
 */
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import './store/index'; // registers 'acrossai/abilities' store with the global wp.data registry
import AbilitiesManager from './components/AbilitiesManager';

// Register nonce middleware for all apiFetch requests.
const config = window.acrossaiAbilitiesManager || {};
if ( config.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}

// Mount the React app.
// No <Provider> needed — the store is registered globally via register() in store/index.js.
const rootEl = document.getElementById( 'acrossai-abilities-root' );
if ( rootEl ) {
	createRoot( rootEl ).render( <AbilitiesManager /> );
}
