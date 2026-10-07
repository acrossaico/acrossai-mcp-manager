/* global HTMLElement */
/**
 * n8n admin — Bearer Auth panel handler (Feature 013).
 *
 * Delegated click handler on `.acrossai-mcp-n8n__generate-token-btn` reads the
 * server id + sibling TTL <select> value, POSTs to the REST endpoint with the
 * WordPress REST nonce, and injects the minted token into the sibling
 * `.acrossai-mcp-n8n__result` element as a masked <input type="password">
 * with Reveal + Copy controls.
 *
 * Copy/Reveal/fallbackCopy/postAdmin helpers are structurally identical to
 * those in `src/js/ai-connectors.js` (Feature 003) — extracted per spec so
 * the two surfaces do not diverge on Copy/Reveal semantics. The n8n global is
 * `window.acrossaiMcpN8n`; the existing `window.acrossaiMcpConnectors` global
 * is left untouched.
 *
 * C5 discipline: the token value is written ONLY to the masked input's
 * `.value` and to the OS clipboard via the Copy button. It NEVER appears in
 * data-* attributes, alt text, localStorage, sessionStorage, cookies, or URL
 * fragments.
 */

( function() {
	'use strict';

	const CFG = window.acrossaiMcpN8n || {};
	const i18n = CFG.i18n || {};

	function fallbackCopy( text ) {
		const ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch {
			ok = false;
		}
		document.body.removeChild( ta );
		return ok;
	}

	function handleCopy( button, text ) {
		const done = function() {
			const original = button.dataset.originalLabel || button.textContent;
			if ( ! button.dataset.originalLabel ) {
				button.dataset.originalLabel = original;
			}
			button.textContent = i18n.copied || 'Copied!';
			window.setTimeout( function() {
				button.textContent = button.dataset.originalLabel;
			}, 1500 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, function() {
				if ( fallbackCopy( text ) ) {
					done();
				}
			} );
			return;
		}
		if ( fallbackCopy( text ) ) {
			done();
		}
	}

	function handleReveal( button, input ) {
		if ( input.type === 'password' ) {
			input.type = 'text';
			button.textContent = i18n.hide || 'Hide';
		} else {
			input.type = 'password';
			button.textContent = i18n.reveal || 'Reveal';
		}
	}

	function postAdmin( url, body, nonce ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( body || {} ),
		} ).then( function( res ) {
			return res
				.json()
				.catch( function() {
					return { code: 'invalid_json', status: res.status };
				} )
				.then( function( data ) {
					if ( ! res.ok ) {
						const err = new Error(
							( data && ( data.message || data.code ) ) ||
								i18n.error ||
								'Request failed',
						);
						err.status = res.status;
						err.code = data && data.code;
						throw err;
					}
					return data;
				} );
		} );
	}

	function renderResult( target, data ) {
		while ( target.firstChild ) {
			target.removeChild( target.firstChild );
		}
		if ( ! data || typeof data.access_token !== 'string' ) {
			target.textContent = i18n.error || 'Unexpected response.';
			return;
		}

		const wrap = document.createElement( 'div' );
		wrap.className = 'acrossai-mcp-connector__copy-row acrossai-mcp-n8n__token-row';

		const label = document.createElement( 'label' );
		label.className = 'acrossai-mcp-n8n__label';
		label.textContent = data.regenerated
			? i18n.tokenRegenerated || 'Bearer token (regenerated — your previous token was revoked):'
			: i18n.tokenIssued || 'Bearer token (copy it now — shown once):';
		wrap.appendChild( label );

		const input = document.createElement( 'input' );
		input.type = 'password';
		input.readOnly = true;
		input.className = 'acrossai-mcp-connector__input';
		input.value = data.access_token;
		wrap.appendChild( input );

		const revealBtn = document.createElement( 'button' );
		revealBtn.type = 'button';
		revealBtn.className = 'button acrossai-mcp-n8n__reveal-btn';
		revealBtn.textContent = i18n.reveal || 'Reveal';
		revealBtn.addEventListener( 'click', function() {
			handleReveal( revealBtn, input );
		} );
		wrap.appendChild( revealBtn );

		const copyBtn = document.createElement( 'button' );
		copyBtn.type = 'button';
		copyBtn.className = 'button button-primary acrossai-mcp-n8n__copy-btn';
		copyBtn.textContent = i18n.copy || 'Copy';
		copyBtn.addEventListener( 'click', function() {
			handleCopy( copyBtn, data.access_token );
		} );
		wrap.appendChild( copyBtn );

		target.appendChild( wrap );

		if ( data.expires_at ) {
			const meta = document.createElement( 'p' );
			meta.className = 'description acrossai-mcp-n8n__token-expiry';
			const expiresLabel = i18n.expiresAt || 'Expires';
			const dt = new Date( data.expires_at * 1000 );
			meta.innerHTML =
				'<em>' +
				expiresLabel +
				' ' +
				dt.toISOString().replace( 'T', ' ' ).replace( /\.\d+Z$/, ' UTC' ) +
				'</em>';
			target.appendChild( meta );
		}
	}

	document.addEventListener( 'click', function( event ) {
		const target = event.target;
		if (
			! ( target instanceof HTMLElement ) ||
			! target.classList.contains( 'acrossai-mcp-n8n__generate-token-btn' )
		) {
			return;
		}
		event.preventDefault();

		const serverId = parseInt( target.getAttribute( 'data-server-id' ) || '0', 10 );
		if ( serverId <= 0 ) {
			return;
		}

		// Endpoint URL is rendered server-side per button so we can't
		// mis-substitute placeholders. Nonce is likewise per-button (fresh
		// on each page render). Both fall back to the localized CFG bag
		// for BC in case a subclass renders the button without them.
		const endpoint = target.getAttribute( 'data-endpoint' ) || '';
		const nonce = target.getAttribute( 'data-nonce' ) || CFG.nonce || '';
		if ( ! endpoint || ! nonce ) {
			return;
		}

		const container = target.closest( '.acrossai-mcp-n8n' );
		const resultTarget = container
			? container.querySelector( '[data-acrossai-n8n-result]' )
			: null;
		const ttlSelect = container
			? container.querySelector( '[data-acrossai-n8n-ttl]' )
			: null;
		const ttlSeconds = ttlSelect ? parseInt( ttlSelect.value, 10 ) : 2592000;

		if ( ! resultTarget ) {
			return;
		}

		const original = target.textContent;
		target.disabled = true;
		target.textContent = i18n.working || 'Generating…';

		postAdmin( endpoint, { ttl_seconds: ttlSeconds }, nonce )
			.then( function( data ) {
				renderResult( resultTarget, data );
				target.textContent =
					i18n.regenerate || 'Regenerate Token';
			} )
			.catch( function( err ) {
				const msg = ( err && err.message ) || i18n.error || 'Failed to generate token.';
				resultTarget.textContent = msg;
				target.textContent = original;
			} )
			.then( function() {
				target.disabled = false;
			} );
	} );

	// Copy button on the MCP URL row (non-token — no security-sensitive path).
	document.addEventListener( 'click', function( event ) {
		const target = event.target;
		if (
			! ( target instanceof HTMLElement ) ||
			! target.classList.contains( 'acrossai-mcp-n8n__copy-btn' ) ||
			! target.hasAttribute( 'data-acrossai-n8n-copy-target' )
		) {
			return;
		}
		event.preventDefault();
		const text = target.getAttribute( 'data-acrossai-n8n-copy-target' ) || '';
		if ( text ) {
			handleCopy( target, text );
		}
	} );

	// STEP 1 (Header Auth): Generate Application Password. Uses mcp-manager's
	// /acrossai-mcp-manager/v1/generate-app-password endpoint. Own handler
	// (not the mcp-manager `.generate-app-password` handler) so we can pass
	// `name: 'n8n'` in the body and render the returned password as a
	// masked input + Reveal + Copy widget consistent with the Bearer Auth
	// panel's token result.
	function renderAppPasswordResult( target, data ) {
		while ( target.firstChild ) {
			target.removeChild( target.firstChild );
		}
		if ( ! data || typeof data.password !== 'string' ) {
			target.textContent = i18n.error || 'Unexpected response.';
			return;
		}

		const outer = document.createElement( 'div' );
		outer.className = 'acrossai-mcp-n8n__app-password-block';

		// Label ABOVE the input row (matches the "MCP endpoint URL" pattern).
		const label = document.createElement( 'p' );
		label.className =
			'acrossai-mcp-connector__label acrossai-mcp-n8n__field-label';
		label.textContent =
			i18n.appPasswordIssued ||
			'Application Password (copy it now — shown once):';
		outer.appendChild( label );

		// Input + Reveal + Copy in a horizontal flex row.
		const row = document.createElement( 'div' );
		row.className =
			'acrossai-mcp-connector__copy-row acrossai-mcp-n8n__app-password-row';

		const pwInput = document.createElement( 'input' );
		pwInput.type = 'password';
		pwInput.readOnly = true;
		pwInput.className =
			'acrossai-mcp-connector__input regular-text code';
		pwInput.value = data.password;
		row.appendChild( pwInput );

		const revealBtn = document.createElement( 'button' );
		revealBtn.type = 'button';
		revealBtn.className = 'button acrossai-mcp-n8n__reveal-btn';
		revealBtn.textContent = i18n.reveal || 'Reveal';
		revealBtn.addEventListener( 'click', function() {
			handleReveal( revealBtn, pwInput );
		} );
		row.appendChild( revealBtn );

		const copyBtn = document.createElement( 'button' );
		copyBtn.type = 'button';
		copyBtn.className = 'button button-primary acrossai-mcp-n8n__copy-btn';
		copyBtn.textContent = i18n.copy || 'Copy';
		copyBtn.addEventListener( 'click', function() {
			handleCopy( copyBtn, data.password );
		} );
		row.appendChild( copyBtn );

		outer.appendChild( row );

		if ( data.username ) {
			const meta = document.createElement( 'p' );
			meta.className =
				'description acrossai-mcp-n8n__app-password-meta';
			meta.textContent =
				( i18n.username || 'Username' ) + ': ' + data.username;
			outer.appendChild( meta );
		}

		target.appendChild( outer );
	}

	document.addEventListener( 'click', function( event ) {
		const target = event.target;
		if (
			! ( target instanceof HTMLElement ) ||
			! target.classList.contains(
				'acrossai-mcp-n8n__generate-app-password-btn',
			)
		) {
			return;
		}
		event.preventDefault();

		const endpoint = target.getAttribute( 'data-endpoint' ) || '';
		const nonce = target.getAttribute( 'data-nonce' ) || '';
		const name = target.getAttribute( 'data-name' ) || 'n8n';
		if ( ! endpoint || ! nonce ) {
			return;
		}

		const serverId = parseInt(
			target.getAttribute( 'data-server-id' ) || '0',
			10,
		);

		const section = target.closest(
			'.acrossai-mcp-connector-panel__setup-section',
		);
		const resultTarget = section
			? section.querySelector( '[data-acrossai-n8n-app-password-result]' )
			: null;
		const statusEl = section
			? section.querySelector( '.acrossai-mcp-n8n__app-password-status' )
			: null;

		const originalLabel = target.textContent || '';
		target.disabled = true;
		target.textContent = i18n.working || 'Generating…';
		if ( statusEl ) {
			statusEl.textContent = '';
		}

		window
			.fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { server_id: serverId, name } ),
			} )
			.then( function( res ) {
				return res
					.json()
					.catch( function() {
						return { code: 'invalid_json', status: res.status };
					} )
					.then( function( data ) {
						if ( ! res.ok ) {
							const err = new Error(
								( data && ( data.message || data.code ) ) ||
									i18n.error ||
									'Request failed',
							);
							err.status = res.status;
							err.code = data && data.code;
							throw err;
						}
						return data;
					} );
			} )
			.then( function( data ) {
				if ( resultTarget ) {
					renderAppPasswordResult( resultTarget, data );
				}
				target.textContent =
					i18n.regenerateAppPassword ||
					'Regenerate Application Password';
			} )
			.catch( function( err ) {
				const msg =
					( err && err.message ) ||
					i18n.error ||
					'Failed to generate password.';
				if ( statusEl ) {
					statusEl.textContent = msg;
					statusEl.className =
						'acrossai-mcp-n8n__app-password-status acrossai-mcp-n8n__app-password-status--error';
				}
				target.textContent = originalLabel;
			} )
			.then( function() {
				target.disabled = false;
			} );
	} );
}() );
