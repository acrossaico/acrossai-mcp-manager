/**
 * Shared AccessControlEditor component.
 *
 * Extracted from src/js/access-control.js in F069 T032 so BOTH the existing
 * per-server-edit tab (mounted at #acrossai-mcp-ac-root by access-control.js)
 * AND the Quick Connect via AcrossAI wizard's Step 2 mount the SAME component with the
 * SAME security invariants — no copy/paste, no drift.
 *
 * REF-002 continuity contract — the pre-refactor tab bootstrap had:
 *   1. apiFetch.createNonceMiddleware(config.nonce) wiring (moved to callers)
 *   2. AccessControl component render with pluginSlug/namespace/resourceKey/
 *      restApiRoot/nonce props (preserved here as component props)
 *   3. No dangerouslySetInnerHTML anywhere
 *
 * Post-refactor: this component preserves item 2 verbatim; items 1 + 3 are
 * caller-side (bootstrap files wire the nonce middleware; component itself
 * never uses innerHTML).
 *
 * @package AcrossAI_MCP_Manager
 */

import { createElement } from '@wordpress/element';
import { AccessControl } from '@wpb/access-control';

/**
 * Props:
 *   pluginSlug     — vendor filter identifier ('acrossai-mcp-manager')
 *   namespace      — REST namespace ('acrossai-mcp-manager')
 *   resourceKey    — server slug the rule scopes to
 *   restApiRoot    — usually '/wp-json'
 *   nonce          — REST nonce string (also used by any nested apiFetch calls)
 *   title          — optional heading override
 *   description    — optional description override
 *   saveLabel      — optional save-button label override
 *   hideSaveButton — when true, vendor renders no save button. Callers that
 *                    hide the button MUST call the vendor's PUT/DELETE
 *                    endpoint themselves and typically pair this with onChange
 *                    to track the selection. Used by F069 Step 3 to merge
 *                    save into the wizard's Continue button.
 *   onChange       — fired with (selectedKey, selectedOptions) on every
 *                    selection change AND once after initial load. Callers
 *                    tracking state for external-save flows depend on this.
 *   onSave         — fired with (selectedKey, selectedOptions) after a
 *                    successful vendor-button save. Not fired when
 *                    hideSaveButton is true.
 */
const AccessControlEditor = ( {
	pluginSlug,
	namespace = 'acrossai-mcp-manager',
	resourceKey,
	restApiRoot = '/wp-json',
	nonce = '',
	title,
	description,
	saveLabel,
	hideSaveButton = false,
	onChange,
	onSave,
} ) => {
	if ( ! pluginSlug || ! resourceKey ) {
		return null;
	}
	return createElement( AccessControl, {
		pluginSlug,
		namespace,
		resourceKey,
		restApiRoot,
		nonce,
		title: title || undefined,
		description: description || undefined,
		saveLabel: saveLabel || undefined,
		hideSaveButton,
		onChange,
		onSave,
	} );
};

export default AccessControlEditor;
