/**
 * Backend bundle entry — bundled into build/js/backend.js by @wordpress/scripts.
 *
 * Ships the F013 copy-to-clipboard handler and the confirm-before-submit
 * gate for destructive buttons. The US4 adapter-notice dismissal binding
 * lived here until the persistent-notice pathway migrated to the shared
 * acrossai_notices filter (main-menu 0.0.30) — vendor now owns dismissal
 * via SummaryNoticeEmitter's fingerprint mechanism, no per-plugin JS needed.
 */

/* global Element, HTMLInputElement, HTMLTextAreaElement */

/**
 * Feature 013 — Copy-to-clipboard handler.
 *
 * Contract (ported from the reference plugin's assets/admin.js):
 *   - Buttons carry `.copy-to-clipboard` and `data-field="<textarea-id>"`.
 *   - On click, copy the referenced textarea/input value to the clipboard.
 *   - On success, flash the button label to "✓ Copied!" for 2 s, then revert.
 *
 * Also supports the F013-original `data-copy-target="<css-selector>"`
 * shape so buttons still emitting that attribute keep working during
 * cross-context reuse.
 *
 * Uses `navigator.clipboard.writeText()` when available; falls back to
 * `document.execCommand('copy')` for older browsers or insecure contexts.
 */
/**
 * Feature 013 — Confirm-before-submit handler for destructive buttons.
 *
 * Ported from the reference plugin's inline `onsubmit="return confirm(...)"`.
 * Buttons carry `data-acrossai-confirm="<localized message>"`; on click, we
 * show a browser confirm() dialog and cancel the submit if the user backs
 * out. Uses event delegation so it works for dynamically-injected buttons.
 *
 * Currently consumed by DangerZoneTab's "Delete Server" button. Any future
 * destructive action (revoke, rotate, etc.) can opt in by emitting the same
 * data attribute — no per-tab JS required.
 */
( function() {
	if ( typeof document === 'undefined' ) {
		return;
	}

	document.addEventListener( 'click', function( event ) {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		const button = target.closest( '[data-acrossai-confirm]' );
		if ( ! button ) {
			return;
		}
		const message = button.getAttribute( 'data-acrossai-confirm' );
		if ( ! message ) {
			return;
		}
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( message ) ) {
			event.preventDefault();
			event.stopPropagation();
		}
	}, true );
}() );

/**
 * Feature — Generate New Application Password button.
 *
 * Wires the button emitted by AbstractClientRenderer::passwords_generate_button().
 * Reference-plugin equivalent lives in assets/admin.js `generatePassword()`.
 *
 * On click:
 *   POST {data-endpoint} with { server_id: <data-server-id> }
 *   Header: X-WP-Nonce: <data-nonce>
 * On success:
 *   1. Show the plaintext password once beside the button (adjacent .acrossai-generate-app-password-status).
 *   2. Locate `#acrossai-mcp-{slug}-config-{serverId}` textarea and replace the
 *      env.WP_API_PASSWORD placeholder ("(paste generated password here)")
 *      with the real password, matching the reference plugin's updateConfig().
 *   3. Update button label to "Regenerate Application Password".
 */
( function() {
	if ( typeof document === 'undefined' ) {
		return;
	}

	const PLACEHOLDER = '(paste generated password here)';

	function injectPasswordIntoConfig( textareaId, password ) {
		const textarea = document.getElementById( textareaId );
		if ( ! textarea || ! textarea.value ) {
			return false;
		}
		try {
			const config = JSON.parse( textarea.value );
			let mutated = false;
			// Walk every top-level key (mcpServers/servers/etc.).
			Object.values( config ).forEach( ( servers ) => {
				if ( servers && typeof servers === 'object' ) {
					Object.values( servers ).forEach( ( serverBlock ) => {
						if (
							serverBlock &&
							serverBlock.env &&
							typeof serverBlock.env === 'object'
						) {
							if ( PLACEHOLDER === serverBlock.env.WP_API_PASSWORD || '' === serverBlock.env.WP_API_PASSWORD ) {
								serverBlock.env.WP_API_PASSWORD = password;
								mutated = true;
							}
						}
					} );
				}
			} );
			if ( mutated ) {
				textarea.value = JSON.stringify( config, null, 2 );
			}
			return mutated;
		} catch {
			return false;
		}
	}

	function renderStatus( statusEl, message, kind ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = '';
		const wrap = document.createElement( 'span' );
		wrap.className = 'notice notice-' + ( 'success' === kind ? 'success' : 'error' ) + ' inline';
		wrap.style.display = 'inline-block';
		wrap.style.marginLeft = '1em';
		wrap.style.padding = '2px 8px';
		wrap.textContent = message;
		statusEl.appendChild( wrap );
	}

	document.addEventListener( 'click', function( event ) {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		const button = target.closest( '.generate-app-password' );
		if ( ! button || button.disabled ) {
			return;
		}
		event.preventDefault();

		const endpoint = button.getAttribute( 'data-endpoint' );
		const nonce = button.getAttribute( 'data-nonce' );

		if ( ! endpoint || ! nonce ) {
			return;
		}

		const serverId = parseInt( button.getAttribute( 'data-server-id' ) || '0', 10 );
		const clientSlug = button.getAttribute( 'data-client-slug' ) || '';

		const statusEl = button.parentNode
			? button.parentNode.querySelector( '.acrossai-generate-app-password-status' )
			: null;
		const originalLabel = button.textContent;
		button.disabled = true;
		button.textContent = 'Generating…';

		fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( { server_id: serverId } ),
		} )
			.then( ( response ) =>
				response.json().then( ( body ) => ( { ok: response.ok, body } ) ),
			)
			.then( ( { ok, body } ) => {
				if ( ! ok || ! body || ! body.password ) {
					const msg =
						( body && ( body.message || body.data?.message ) ) ||
						'Failed to generate password.';
					renderStatus( statusEl, msg, 'error' );
					button.textContent = originalLabel;
					return;
				}
				const injected = injectPasswordIntoConfig(
					'acrossai-mcp-' + clientSlug + '-config-' + serverId,
					body.password,
				);
				const displayMsg = injected
					? 'Password: ' + body.password + ' (also injected into the config below — shown only once).'
					: 'Password: ' + body.password + ' (shown only once — copy it now).';
				renderStatus( statusEl, displayMsg, 'success' );
				button.textContent = 'Regenerate Application Password';
				button.classList.add( 'has-password' );
			} )
			.catch( ( err ) => {
				renderStatus( statusEl, 'Network error: ' + err.message, 'error' );
				button.textContent = originalLabel;
			} )
			.finally( () => {
				button.disabled = false;
			} );
	} );
}() );

( function() {
	if ( typeof document === 'undefined' ) {
		return;
	}

	function readSourceText( source ) {
		if ( source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement ) {
			return source.value;
		}
		return source.textContent || '';
	}

	function copyViaExecCommand( text ) {
		const scratch = document.createElement( 'textarea' );
		scratch.value = text;
		scratch.style.position = 'fixed';
		scratch.style.top = '0';
		scratch.style.left = '0';
		scratch.style.width = '2em';
		scratch.style.height = '2em';
		scratch.style.opacity = '0';
		document.body.appendChild( scratch );
		scratch.focus();
		scratch.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch {
			ok = false;
		}
		document.body.removeChild( scratch );
		return ok;
	}

	function flashCopied( button ) {
		if ( ! button.dataset.originalLabel ) {
			button.dataset.originalLabel = button.textContent || '';
		}
		button.textContent = '✓ Copied!';
		button.disabled = true;
		setTimeout( function() {
			button.textContent = button.dataset.originalLabel;
			button.disabled = false;
		}, 2000 );
	}

	function findSourceForButton( button ) {
		const fieldId = button.getAttribute( 'data-field' );
		if ( fieldId ) {
			return document.getElementById( fieldId );
		}
		const selector = button.getAttribute( 'data-copy-target' );
		if ( selector ) {
			return document.querySelector( selector );
		}
		return null;
	}

	document.addEventListener( 'click', function( event ) {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		const button = target.closest( '.copy-to-clipboard, [data-copy-target]' );
		if ( ! button ) {
			return;
		}

		const source = findSourceForButton( button );
		if ( ! source ) {
			return;
		}

		const text = readSourceText( source );
		if ( ! text ) {
			return;
		}

		event.preventDefault();

		if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			navigator.clipboard.writeText( text ).then(
				function() {
					flashCopied( button );
				},
				function() {
					if ( copyViaExecCommand( text ) ) {
						flashCopied( button );
					}
				},
			);
			return;
		}

		if ( copyViaExecCommand( text ) ) {
			flashCopied( button );
		}
	} );
}() );
