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
 *      placeholder ("(paste generated password here)") with the real password.
 *      Textual, not structural: the config may be JSON or TOML, and the key
 *      holding the password differs per client (`env` vs `environment`).
 *   3. Update button label to "Regenerate Application Password".
 */
( function() {
	if ( typeof document === 'undefined' ) {
		return;
	}

	const PLACEHOLDER = '(paste generated password here)';

	/**
	 * Replace the password placeholder wherever it appears, as text.
	 *
	 * This used to JSON.parse the textarea, walk `serverBlock.env` and
	 * re-stringify. That was wrong in three ways and each had shipped:
	 *
	 *   1. It only looked at `env`, so OpenCode — whose key is `environment` —
	 *      silently got no password at all.
	 *   2. It re-stringified without JSON_UNESCAPED_SLASHES, so every URL came
	 *      back with escaped slashes and no longer matched the PHP original
	 *      byte for byte.
	 *   3. It assumed JSON. Codex's config is TOML, so `JSON.parse` threw and
	 *      the catch swallowed it — the operator saw their password beside the
	 *      button and an unchanged config below, with nothing explaining why.
	 *
	 * A textual replace has none of those failure modes and cannot care what
	 * format the config is in. The placeholder is a literal constant emitted by
	 * `AbstractMCPClient::safe_token()`, so matching it as text is exact.
	 * `Step11_ClientDetail.jsx` has always done it this way.
	 *
	 * Targets EVERY element carrying data-acrossai-password-target, not one id.
	 * The screen now shows two routes — the config block and the setup prompt —
	 * and both embed the same placeholder. Addressing a single id meant
	 * generating a password updated the config and silently left the prompt
	 * holding "(paste generated password here)", so anyone who copied the
	 * prompt pasted a placeholder into their AI client.
	 *
	 * @param {string} password The generated Application Password.
	 * @return {number} How many fields were updated.
	 */
	function injectPasswordIntoConfig( password ) {
		const targets = document.querySelectorAll(
			'[data-acrossai-password-target]',
		);
		let updated = 0;

		targets.forEach( ( field ) => {
			if ( ! field.value || ! field.value.includes( PLACEHOLDER ) ) {
				return;
			}
			field.value = field.value.split( PLACEHOLDER ).join( password );
			updated++;
		} );

		return updated;
	}

	/**
	 * Switch between the two setup routes without reloading.
	 *
	 * The picker is server-rendered links with real hrefs, and the server
	 * honours ?setup= — so with JavaScript off this does nothing and the links
	 * still work. With JavaScript on, reloading would be actively harmful: the
	 * Application Password is shown exactly once and cannot be re-fetched, so
	 * a reload mid-setup discards a credential the operator may not have
	 * copied yet.
	 */
	// Registered at module level, matching how the other delegated handlers in
	// this file are wired — there is no init() convention here.
	document.addEventListener( 'click', ( event ) => {
		const card = event.target.closest(
			'[data-acrossai-route-target]',
		);
		if ( ! card ) {
			return;
		}

		const wanted = card.getAttribute( 'data-acrossai-route-target' );
		const panels = document.querySelectorAll(
			'[data-acrossai-route]',
		);
		if ( ! wanted || panels.length === 0 ) {
			return; // Nothing rendered to toggle — let the link navigate.
		}

		event.preventDefault();

		panels.forEach( ( panel ) => {
			panel.hidden =
				panel.getAttribute( 'data-acrossai-route' ) !== wanted;
		} );

		document
			.querySelectorAll( '[data-acrossai-route-target]' )
			.forEach( ( link ) => {
				const on =
					link.getAttribute( 'data-acrossai-route-target' ) ===
					wanted;
				link.classList.toggle( 'is-selected', on );
				if ( on ) {
					link.setAttribute( 'aria-current', 'true' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );

		// Keep the URL honest so a refresh or a shared link lands on the route
		// the operator is actually looking at.
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( {}, '', card.href );
		}
	} );

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
			// `client` feeds the credential's NAME, which is this feature's
			// durable provenance record (B76): the activity-log entry ages out
			// on that log's retention, the name lives as long as the
			// credential. The button has always carried data-client-slug; it
			// was simply never sent.
			body: JSON.stringify( {
				server_id: serverId,
				client: clientSlug,
			} ),
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
				const injected = injectPasswordIntoConfig( body.password );
				const displayMsg = injected
					? 'Password: ' + body.password + ' (also filled into the steps below — shown only once).'
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

/**
 * 0.3.6 — show the connect-message box only when "Custom" is selected.
 *
 * The Overview tab prefills the box with what the server sends today, which is
 * useful when you are about to edit it and misleading when you are not: left
 * visible under "System default" it reads as an editable field whose contents
 * are about to be saved, when nothing in it will be.
 *
 * PHP renders the wrapper with `hidden` already correct, so the first paint
 * needs no script. This only keeps it in step as the radios change.
 *
 * Delegated from the document rather than bound per form: the panel is markup
 * PHP prints, and a listener attached at load would miss anything re-rendered
 * later.
 */
( function() {
	const WRAPPER = '.acrossai-mcp-instructions__custom';
	const RADIO = 'input[name="instructions_mode"]';

	/**
	 * Match the box's visibility to the chosen radio.
	 *
	 * @param {Element} form The connect-message form.
	 */
	function sync( form ) {
		const wrapper = form.querySelector( WRAPPER );
		const custom = form.querySelector( RADIO + '[value="custom"]' );

		if ( ! wrapper || ! custom ) {
			return;
		}

		wrapper.hidden = ! custom.checked;
	}

	document.addEventListener( 'change', function( event ) {
		const target = event.target;

		if ( ! ( target instanceof Element ) || ! target.matches( RADIO ) ) {
			return;
		}

		const form = target.closest( 'form' );

		if ( form ) {
			sync( form );
		}
	} );
}() );
