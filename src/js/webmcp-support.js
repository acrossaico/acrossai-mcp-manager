/**
 * F093 — browser support indicator for the WebMCP admin page.
 *
 * Reports whether THIS browser can see WebMCP tools at all. Without it an
 * administrator on Safari — or on Chrome without the flag — switches the
 * feature on, observes no change anywhere, and files a bug. A few lines here
 * prevent the single most likely support ticket this feature generates.
 *
 * Detection only. It never writes to `document.modelContext`: a polyfill
 * that replaces the host getter makes NATIVE agents blind, because ChatGPT's
 * browser and Chrome's origin-trial consumers read the host object directly.
 * Reading is always safe; defining is not.
 *
 * `navigator.modelContext` is deliberately not consulted. It was the
 * pre-standard location and was removed in Chrome 152, so treating it as
 * support would tell an admin their browser works when it cannot register a
 * single tool.
 *
 * @package
 */

( function() {
	'use strict';

	const el = document.getElementById( 'acrossai-webmcp-support' );
	if ( ! el ) {
		return;
	}

	const strings = window.acrossaiWebmcpSupport || {};

	const supported =
		typeof document !== 'undefined' &&
		!! document.modelContext &&
		typeof document.modelContext.registerTool === 'function';

	el.textContent = supported
		? strings.supported || 'Supported'
		: strings.unsupported || 'Not supported';

	// Inline colour rather than a stylesheet: this is one word on one screen,
	// and a dedicated CSS file for it would be more to keep in step than it
	// is worth.
	el.style.color = supported ? '#008a20' : '#b32d2e';
	el.style.fontWeight = '600';

	if ( ! supported && strings.hint ) {
		const hint = document.createElement( 'p' );
		hint.className = 'description';
		hint.textContent = strings.hint;
		el.parentNode.appendChild( hint );
	}
}() );
