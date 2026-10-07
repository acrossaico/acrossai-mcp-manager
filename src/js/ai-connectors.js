/* eslint-disable no-alert -- F095: ported verbatim from acrossai-pro. Most of
 * these are window.confirm() guards in front of destructive revoke/delete
 * actions, so they are a safety feature, not laziness — dropping them to
 * satisfy the rule would remove a confirmation step from an irreversible
 * operation. Replacing them with a @wordpress/components modal is a UI feature
 * in its own right and does not belong inside a 9,000-line migration; tracked
 * as T076. */
/**
 * F021 Phase 9 — AI Connectors shared JS.
 *
 * Delegated event handlers on the tab wrapper. Every connector card
 * rendered by `AbstractConnectorProfile::render_default_card` inherits
 * these behaviors:
 *   • Generate credentials → POST to /oauth/generate-client
 *   • Regenerate credentials → confirm + POST
 *   • Copy button → clipboard writeText with execCommand fallback
 *   • Reveal button → toggle password input type
 *
 * Server id + REST nonce come from the tab wrapper's data attributes:
 *   .acrossai-mcp-ai-connectors[data-server-id][data-wp-rest-nonce]
 *
 * Connector slug comes from the section's data attribute:
 *   .acrossai-mcp-connector[data-acrossai-connector-slug]
 *
 * Localized i18n bundle: `acrossaiMcpConnectors` (via wp_localize_script).
 *
 * Class + attribute contract is PUBLIC API — marked @experimental
 * until 1.0.0. Any breaking change requires a memory entry.
 */

import '../scss/ai-connectors.scss';

( function() {
	'use strict';

	const i18n = ( typeof window !== 'undefined' && window.acrossaiMcpConnectors ) || {};
	const REST_ENDPOINT = i18n.restEndpoint || '';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		const wrappers = document.querySelectorAll( '.acrossai-mcp-ai-connectors' );
		if ( 0 === wrappers.length ) {
			return;
		}

		wrappers.forEach( ( wrapper ) => wrapper.addEventListener( 'click', handleClick.bind( null, wrapper ) ) );
	}

	function handleClick( wrapper, ev ) {
		const target = ev.target.closest(
			'.acrossai-mcp-connector__generate-btn, .acrossai-mcp-connector__regenerate-btn, ' +
			'[data-acrossai-copy], [data-acrossai-reveal], ' +
			'.acrossai-mcp-connector-panel__revoke-btn, .acrossai-mcp-connector-panel__delete-btn, ' +
			'.acrossai-mcp-connector-panel__revoke-user-btn, .acrossai-mcp-connector-panel__delete-user-btn, ' +
			'.acrossai-mcp-connector-panel__revoke-grant-btn, ' +
			'.acrossai-mcp-connector-panel__revoke-all-btn, ' +
			'.acrossai-mcp-connector-panel__server-revoke-all-btn, ' +
			'.acrossai-mcp-connector-panel__nuclear-btn, ' +
			'.acrossai-mcp-connector-panel__approve-btn, ' +
			'.acrossai-mcp-connector-panel__deny-btn, ' +
			'.acrossai-mcp-connector-panel__revoke-approval-btn, ' +
			'.acrossai-mcp-connector-panel__server-approve-btn, ' +
			'.acrossai-mcp-connector-panel__server-deny-btn, ' +
			'.acrossai-mcp-connector-panel__server-revoke-approval-btn',
		);
		if ( ! target ) {
			return;
		}
		ev.preventDefault();

		// Server-wide handlers first — they subclass the nuclear-btn class for
		// styling reuse, so match them before falling through to the per-slug
		// nuclear handler.
		if ( target.matches( '.acrossai-mcp-connector-panel__server-revoke-all-btn' ) ) {
			handleServerRevokeAll( wrapper, target );
			return;
		}
		if ( target.matches( '.acrossai-mcp-connector-panel__server-approve-btn' ) ) {
			handleServerApprove( wrapper, target );
			return;
		}
		if ( target.matches( '.acrossai-mcp-connector-panel__server-deny-btn' ) ) {
			handleServerDeny( wrapper, target );
			return;
		}
		if ( target.matches( '.acrossai-mcp-connector-panel__server-revoke-approval-btn' ) ) {
			handleServerRevokeApproval( wrapper, target );
			return;
		}

		if ( target.matches( '.acrossai-mcp-connector__generate-btn' ) ) {
			handleGenerate( wrapper, target, false );
		} else if ( target.matches( '.acrossai-mcp-connector__regenerate-btn' ) ) {
			handleGenerate( wrapper, target, true );
		} else if ( target.matches( '[data-acrossai-copy]' ) ) {
			handleCopy( target );
		} else if ( target.matches( '[data-acrossai-reveal]' ) ) {
			handleReveal( target );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__revoke-btn' ) ) {
			handleAdminAction( wrapper, target, 'revoke-client-tokens', 'Revoke every token for this client on this server?' );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__delete-btn' ) ) {
			handleAdminAction( wrapper, target, 'delete-client', 'Delete this client? This revokes every token first and cannot be undone.' );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__revoke-all-btn' ) ) {
			handleRevokeAllServers( wrapper, target );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__revoke-grant-btn' ) ) {
			// Per-grant revoke — one authorization grant (token family) on this
			// server. Sibling grants from the same user/client stay connected.
			handleRevokeGrant( wrapper, target );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__revoke-user-btn' ) ) {
			// F010: per-(user × connector) revoke.
			handleUserConnectorAction(
				wrapper,
				target,
				'revoke-client-tokens',
				'Revoke every token for this user on this connector for this server?',
			);
		} else if ( target.matches( '.acrossai-mcp-connector-panel__delete-user-btn' ) ) {
			// F010: per-(user × connector) delete. Backend keeps the client
			// row alive if other users still have active tokens on it and
			// responds with revoked_only:true, which the handler surfaces.
			handleUserConnectorAction(
				wrapper,
				target,
				'delete-client',
				'Delete this user\'s OAuth client(s) for this connector? If a client is shared with other users, only this user\'s tokens will be revoked and the client row survives. Cannot be undone.',
			);
		} else if ( target.matches( '.acrossai-mcp-connector-panel__nuclear-btn' ) ) {
			handleNuclear( wrapper, target );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__approve-btn' ) ) {
			handleApprove( wrapper, target );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__deny-btn' ) ) {
			handleDenyPending( wrapper, target );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__revoke-approval-btn' ) ) {
			handleRevokeApproval( wrapper, target );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		document.querySelectorAll( '.acrossai-mcp-connector-panel__settings-form' ).forEach( function( form ) {
			form.addEventListener( 'submit', function( ev ) {
				ev.preventDefault();
				const wrapper = form.closest( '.acrossai-mcp-ai-connectors' );
				if ( ! wrapper ) {
					return;
				}
				handleSaveSettings( wrapper, form );
			} );
		} );

		document.querySelectorAll( '.acrossai-mcp-connector-panel__server-settings-form' ).forEach( function( form ) {
			form.addEventListener( 'submit', function( ev ) {
				ev.preventDefault();
				const wrapper = form.closest( '.acrossai-mcp-ai-connectors' );
				if ( ! wrapper ) {
					return;
				}
				handleSaveServerSettings( wrapper, form );
			} );
		} );
	} );

	// ---- F024 admin actions ---------------------------------------------

	function adminBase( wrapper ) {
		const serverId = parseInt( wrapper.getAttribute( 'data-server-id' ), 10 );
		const nonce = wrapper.getAttribute( 'data-wp-rest-nonce' ) || '';
		const base = ( REST_ENDPOINT || '' ).replace( /\/oauth\/generate-client$/, '' );
		return { serverId, nonce, base };
	}

	function postAdmin( wrapper, path, body ) {
		const ctx = adminBase( wrapper );
		if ( ! ctx.base || ! ctx.nonce ) {
			return Promise.reject( new Error( 'missing-ctx' ) );
		}
		return fetch( ctx.base + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ctx.nonce,
			},
			body: JSON.stringify( body ),
		} ).then( function( r ) {
			return r.json().then( function( data ) {
				return { status: r.status, data };
			} );
		} );
	}

	function handleAdminAction( wrapper, btn, path, confirmMsg ) {
		const clientId = btn.getAttribute( 'data-acrossai-client-id' );
		// F032 (T043) — server_id is now REQUIRED in the mutating REST body.
		// Prefer the per-row attribute (emitted by AIConnectorsTab T042);
		// fall back to the wrapper's data-server-id for backward compat.
		const rowServerId = parseInt( btn.getAttribute( 'data-acrossai-server-id' ) || '0', 10 );
		const ctxServerId = parseInt( wrapper.getAttribute( 'data-server-id' ) || '0', 10 );
		const serverId = rowServerId > 0 ? rowServerId : ctxServerId;
		if ( ! clientId || ! serverId ) {
			return;
		}
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/' + path, { client_id: clientId, server_id: serverId } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					// F032 (T043) — surface a distinct error for the cross-server 403 so
					// operators understand it's not a generic permissions issue.
					if ( response.status === 403 && response.data && 'acrossai_mcp_oauth_cross_server' === response.data.code ) {
						window.alert( 'This action can only be performed for the server that owns this client — refresh the page and try again.' );
					} else {
						window.alert( 'Failed. See console.' );
					}
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	// F010: per-(user × connector) admin action. Reads client_ids array from
	// the button, iterates one REST call per client_id (each carrying the
	// user_id filter), then reloads on success. If any delete-client call
	// comes back with revoked_only:true, we surface a notice explaining the
	// client survived because it's shared with other users on this server.
	function handleUserConnectorAction( wrapper, btn, path, confirmMsg ) {
		const userId = parseInt( btn.getAttribute( 'data-acrossai-user-id' ) || '0', 10 );
		const serverId = parseInt( btn.getAttribute( 'data-acrossai-server-id' ) || '0', 10 );
		const slug = btn.getAttribute( 'data-acrossai-connector-slug' ) || '';
		const clientIdsRaw = btn.getAttribute( 'data-acrossai-client-ids' ) || '[]';

		if ( ! userId || ! serverId ) {
			return;
		}

		let clientIds;
		try {
			clientIds = JSON.parse( clientIdsRaw );
		} catch {
			// eslint-disable-next-line no-console
			console.error( 'ai-connectors: bad data-acrossai-client-ids', clientIdsRaw );
			return;
		}
		if ( ! Array.isArray( clientIds ) || clientIds.length === 0 ) {
			return;
		}

		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}
		btn.disabled = true;

		const requests = clientIds.map( function( clientId ) {
			return postAdmin( wrapper, '/oauth/' + path, {
				client_id: String( clientId ),
				server_id: serverId,
				user_id: userId,
			} );
		} );

		Promise.all( requests )
			.then( function( responses ) {
				const failed = responses.filter( function( r ) {
					return r.status < 200 || r.status >= 300;
				} );
				if ( failed.length > 0 ) {
					window.alert( 'Some actions failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( 'ai-connectors: partial failure', failed, 'slug=' + slug );
					btn.disabled = false;
					return;
				}

				const revokedOnly = responses.filter( function( r ) {
					return r.status >= 200 && r.status < 300 && r.data && r.data.revoked_only === true;
				} );

				if ( revokedOnly.length > 0 && 'delete-client' === path ) {
					window.alert(
						revokedOnly.length + ' client row(s) stayed alive because they are shared with other users on this server. ' +
						'This user\'s tokens for those clients were revoked; the client rows themselves were preserved.',
					);
				}

				window.location.reload();
			} )
			.catch( function( err ) {
				// eslint-disable-next-line no-console
				console.error( err );
				btn.disabled = false;
			} );
	}

	// Per-grant revoke. One authorization grant == one token_family_id, minted
	// per consent flow — so two claude.ai accounts connected as the same WP
	// user (sharing one DCR client_id) revoke independently.
	function handleRevokeGrant( wrapper, btn ) {
		const familyId = btn.getAttribute( 'data-acrossai-family-id' ) || '';
		const rowServerId = parseInt( btn.getAttribute( 'data-acrossai-server-id' ) || '0', 10 );
		const ctxServerId = parseInt( wrapper.getAttribute( 'data-server-id' ) || '0', 10 );
		const serverId = rowServerId > 0 ? rowServerId : ctxServerId;
		if ( ! familyId || ! serverId ) {
			return;
		}
		if ( ! window.confirm(
			'Revoke this connection?\n\n' +
			'Every token from this authorization grant on this server will be revoked. ' +
			'Other connections — including other connections by the same user from the same AI host — are unaffected. ' +
			'The user can re-authorize from their AI host to reconnect.',
		) ) {
			return;
		}
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/revoke-grant', { server_id: serverId, token_family_id: familyId } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	function handleRevokeAllServers( wrapper, btn ) {
		const clientId = btn.getAttribute( 'data-acrossai-client-id' );
		if ( ! clientId ) {
			return;
		}
		if ( ! window.confirm(
			'Revoke every token for this client across EVERY server on this site?\n\n' +
			'This is an intentional cross-server operation. Any active AI-host session ' +
			'using this client on any server will disconnect on the next request. Users ' +
			'will need to re-authorize their AI host to reconnect.\n\n' +
			'Cannot be undone.',
		) ) {
			return;
		}
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/revoke-client-tokens-all-servers', { client_id: clientId } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	function handleNuclear( wrapper, btn ) {
		const slug = btn.getAttribute( 'data-acrossai-connector-slug' );
		const confirmMsg = btn.getAttribute( 'data-acrossai-confirm' ) || 'Revoke everything?';
		if ( ! slug ) {
			return;
		}
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/revoke-connector-tokens', { server_id: ctx.serverId, connector_slug: slug } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					const n = response.data && typeof response.data.revoked_count === 'number' ? response.data.revoked_count : 0;
					window.alert( 'Revoked ' + n + ' token' + ( 1 === n ? '' : 's' ) + ' for this connector.' );
					window.location.reload();
				} else {
					window.alert( 'Failed to revoke connector tokens. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function( err ) {
				window.alert( 'Network error. See console.' );
				// eslint-disable-next-line no-console
				console.error( err );
				btn.disabled = false;
			} );
	}

	function handleApprove( wrapper, btn ) {
		const slug = btn.getAttribute( 'data-acrossai-connector-slug' );
		const userId = parseInt( btn.getAttribute( 'data-acrossai-user-id' ), 10 );
		if ( ! slug || ! userId ) {
			return;
		}
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/approve-pending-consent', { server_id: ctx.serverId, connector_slug: slug, user_id: userId } )
			.then( function() {
				window.location.reload();
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	function handleDenyPending( wrapper, btn ) {
		const slug = btn.getAttribute( 'data-acrossai-connector-slug' );
		const userId = parseInt( btn.getAttribute( 'data-acrossai-user-id' ), 10 );
		if ( ! slug || ! userId ) {
			return;
		}
		if ( ! window.confirm( 'Deny this pending approval request?\n\nThe user is removed from the pending list without being approved. They must re-attempt the connect flow from their AI host if they want to try again.' ) ) {
			return;
		}
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/deny-pending-consent', { server_id: ctx.serverId, connector_slug: slug, user_id: userId } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	function handleRevokeApproval( wrapper, btn ) {
		const slug = btn.getAttribute( 'data-acrossai-connector-slug' );
		const userId = parseInt( btn.getAttribute( 'data-acrossai-user-id' ), 10 );
		if ( ! slug || ! userId ) {
			return;
		}
		if ( ! window.confirm( 'Revoke this user\'s approval for this connector?\n\nThis will ALSO revoke every active OAuth token the user holds for this connector on this server — they will be disconnected immediately and their next connect attempt will re-enter the pending flow.\n\n(To opt out of the token cascade, hook the acrossai_mcp_connector_revoke_tokens_on_approval_revoked filter server-side.)' ) ) {
			return;
		}
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/revoke-user-approval', { server_id: ctx.serverId, connector_slug: slug, user_id: userId } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	function handleSaveSettings( wrapper, form ) {
		const slug = form.getAttribute( 'data-acrossai-connector-slug' );
		if ( ! slug ) {
			return;
		}
		const enabledInput = form.querySelector( 'input[name="enabled"]' );
		const approvalInput = form.querySelector( 'input[name="require_admin_approval"]' );
		const enabled = !! ( enabledInput && enabledInput.checked );
		const approval = !! ( approvalInput && approvalInput.checked );

		const ctx = adminBase( wrapper );
		const btn = form.querySelector( 'button[type="submit"]' );
		if ( btn ) {
			btn.disabled = true;
		}

		postAdmin( wrapper, '/oauth/connector-settings', {
			server_id: ctx.serverId,
			connector_slug: slug,
			enabled,
			require_admin_approval: approval,
		} )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed to save. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					if ( btn ) {
						btn.disabled = false;
					}
				}
			} )
			.catch( function() {
				if ( btn ) {
					btn.disabled = false;
				}
			} );
	}

	// ---- Server-wide handlers (top-level Settings / Approved Users) ------

	function handleSaveServerSettings( wrapper, form ) {
		const ctx = adminBase( wrapper );
		const enabledSlugs = [];
		form.querySelectorAll( 'input[name="enabled_slugs[]"]' ).forEach( function( input ) {
			if ( input.checked ) {
				enabledSlugs.push( input.value );
			}
		} );
		const allowOtherInput = form.querySelector( 'input[name="allow_other"]' );
		const approvalInput = form.querySelector( 'input[name="require_admin_approval"]' );
		const allowOther = !! ( allowOtherInput && allowOtherInput.checked );
		const requireApproval = !! ( approvalInput && approvalInput.checked );

		const btn = form.querySelector( 'button[type="submit"]' );
		if ( btn ) {
			btn.disabled = true;
		}

		postAdmin( wrapper, '/oauth/server-settings', {
			server_id: ctx.serverId,
			enabled_slugs: enabledSlugs,
			allow_other: allowOther,
			require_admin_approval: requireApproval,
		} )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed to save. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					if ( btn ) {
						btn.disabled = false;
					}
				}
			} )
			.catch( function() {
				if ( btn ) {
					btn.disabled = false;
				}
			} );
	}

	function handleServerRevokeAll( wrapper, btn ) {
		const confirmMsg = btn.getAttribute( 'data-acrossai-confirm' ) || 'Revoke every token on this server?';
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/revoke-server-tokens', { server_id: ctx.serverId } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					const n = response.data && typeof response.data.revoked_count === 'number' ? response.data.revoked_count : 0;
					window.alert( 'Revoked ' + n + ' token' + ( 1 === n ? '' : 's' ) + ' on this server.' );
					window.location.reload();
				} else {
					window.alert( 'Failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	function handleServerApprove( wrapper, btn ) {
		const userId = parseInt( btn.getAttribute( 'data-acrossai-user-id' ), 10 );
		if ( ! userId ) {
			return;
		}
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/approve-server-pending', { server_id: ctx.serverId, user_id: userId } )
			.then( function() {
				window.location.reload();
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	function handleServerDeny( wrapper, btn ) {
		const userId = parseInt( btn.getAttribute( 'data-acrossai-user-id' ), 10 );
		if ( ! userId ) {
			return;
		}
		if ( ! window.confirm( 'Deny this pending approval request?\n\nThe user is removed from the pending list without being approved. They must re-attempt the connect flow from their AI host if they want to try again.' ) ) {
			return;
		}
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/deny-server-pending', { server_id: ctx.serverId, user_id: userId } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	function handleServerRevokeApproval( wrapper, btn ) {
		const userId = parseInt( btn.getAttribute( 'data-acrossai-user-id' ), 10 );
		if ( ! userId ) {
			return;
		}
		if ( ! window.confirm( 'Revoke this user\'s approval on this server?\n\nThis will ALSO revoke every active OAuth token the user holds on this server (any connector). They will be disconnected immediately and their next connect attempt will re-enter the pending flow.' ) ) {
			return;
		}
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/revoke-server-approval', { server_id: ctx.serverId, user_id: userId } )
			.then( function( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function() {
				btn.disabled = false;
			} );
	}

	// ---- Generate / Regenerate ------------------------------------------

	function handleGenerate( wrapper, btn, isRegenerate ) {
		const section = btn.closest( '.acrossai-mcp-connector' );
		if ( ! section ) {
			return;
		}

		const serverId = parseInt( wrapper.getAttribute( 'data-server-id' ), 10 );
		const nonce = wrapper.getAttribute( 'data-wp-rest-nonce' ) || '';
		const slug = section.getAttribute( 'data-acrossai-connector-slug' ) || '';
		const endpoint = REST_ENDPOINT;

		if ( ! serverId || ! nonce || ! endpoint || ! slug ) {
			flashResult( section, 'error', i18n.missingCtx || 'Missing server context. Reload the page and try again.' );
			return;
		}

		if ( isRegenerate ) {
			const confirmMsg = btn.getAttribute( 'data-acrossai-confirm' ) || i18n.confirmRegenerate || 'Regenerate credentials?';
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}
		}

		btn.disabled = true;
		flashResult( section, 'pending', i18n.working || 'Generating credentials…' );

		fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( { server_id: serverId, connector_slug: slug } ),
		} )
			.then( ( r ) => r.json().then( ( body ) => ( { status: r.status, body } ) ) )
			.then( ( response ) => {
				btn.disabled = false;
				if ( response.status < 200 || response.status >= 300 ) {
					const msg =
						( response.body && ( response.body.message || response.body.error_description ) ) ||
						i18n.failed ||
						'Failed to generate credentials.';
					flashResult( section, 'error', msg );
					return;
				}
				renderCredentials( section, response.body );
			} )
			.catch( () => {
				btn.disabled = false;
				flashResult( section, 'error', i18n.failed || 'Failed to generate credentials.' );
			} );
	}

	// ---- Rendering ------------------------------------------------------

	function renderCredentials( section, body ) {
		const result = section.querySelector( '[data-acrossai-result]' );
		if ( ! result ) {
			return;
		}

		const labels = {
			issued: i18n.issued || 'Credentials generated',
			clientId: i18n.clientId || 'OAuth Client ID',
			secret: i18n.secret || 'OAuth Client Secret (visible once — copy it now)',
			setup: i18n.setup || 'Setup instructions',
			copy: i18n.copy || 'Copy',
			reveal: i18n.reveal || 'Reveal',
		};

		let html = '';
		html += '<h4 class="acrossai-mcp-connector__result-title">' + escapeHtml( labels.issued ) + '</h4>';
		html += '<div class="acrossai-mcp-connector__credentials acrossai-mcp-connector__credentials--fresh">';
		html += '<p class="acrossai-mcp-connector__label">' + escapeHtml( labels.clientId ) + '</p>';
		html += '<div class="acrossai-mcp-connector__copy-row">';
		html += '<input type="text" class="acrossai-mcp-connector__input regular-text code" value="' + escapeAttr( body.client_id || '' ) + '" readonly>';
		html += '<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-copy="client-id">' + escapeHtml( labels.copy ) + '</button>';
		html += '</div>';
		html += '<p class="acrossai-mcp-connector__label">' + escapeHtml( labels.secret ) + '</p>';
		html += '<div class="acrossai-mcp-connector__copy-row">';
		html += '<input type="password" class="acrossai-mcp-connector__input acrossai-mcp-connector__input--secret regular-text code" value="' + escapeAttr( body.client_secret || '' ) + '" readonly autocomplete="off" spellcheck="false">';
		html += '<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-reveal="client-secret">' + escapeHtml( labels.reveal ) + '</button>';
		html += '<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-copy="client-secret">' + escapeHtml( labels.copy ) + '</button>';
		html += '</div>';
		html += '</div>';

		if ( body.setup_instructions_html ) {
			// setup_instructions_html was passed through wp_kses_post server-side
			// (SEC-021-T02). Safe to insert as-is.
			html += '<div class="acrossai-mcp-connector__setup">';
			html += '<h4 class="acrossai-mcp-connector__setup-title">' + escapeHtml( labels.setup ) + '</h4>';
			html += body.setup_instructions_html;
			html += '</div>';
		}

		result.innerHTML = html;
		result.setAttribute( 'data-status', 'success' );
	}

	function flashResult( section, status, message ) {
		const result = section.querySelector( '[data-acrossai-result]' );
		if ( ! result ) {
			return;
		}
		result.setAttribute( 'data-status', status );
		result.innerHTML = '<p class="acrossai-mcp-connector__result-message">' + escapeHtml( message ) + '</p>';
	}

	// ---- Copy / Reveal --------------------------------------------------

	function handleCopy( btn ) {
		const row = btn.closest( '.acrossai-mcp-connector__copy-row' );
		if ( ! row ) {
			return;
		}
		const input = row.querySelector( '.acrossai-mcp-connector__input' );
		if ( ! input ) {
			return;
		}
		const value = input.value || '';

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard
				.writeText( value )
				.then( () => flashCopied( btn ) )
				.catch( () => fallbackCopy( input, btn ) );
		} else {
			fallbackCopy( input, btn );
		}
	}

	function fallbackCopy( input, btn ) {
		const wasPassword = 'password' === input.type;
		if ( wasPassword ) {
			input.type = 'text';
		}
		input.select();
		try {
			document.execCommand( 'copy' );
			flashCopied( btn );
		} catch {
			// Silent — user can still copy manually.
		}
		if ( wasPassword ) {
			input.type = 'password';
		}
		input.blur();
	}

	function flashCopied( btn ) {
		const originalText = btn.textContent;
		btn.textContent = i18n.copied || 'Copied!';
		btn.classList.add( 'acrossai-mcp-connector__copy-btn--copied' );
		setTimeout( () => {
			btn.textContent = originalText;
			btn.classList.remove( 'acrossai-mcp-connector__copy-btn--copied' );
		}, 1500 );
	}

	function handleReveal( btn ) {
		const row = btn.closest( '.acrossai-mcp-connector__copy-row' );
		if ( ! row ) {
			return;
		}
		const input = row.querySelector( '.acrossai-mcp-connector__input--secret' );
		if ( ! input ) {
			return;
		}
		const revealLabel = i18n.reveal || 'Reveal';
		const hideLabel = i18n.hide || 'Hide';

		if ( 'password' === input.type ) {
			input.type = 'text';
			btn.textContent = hideLabel;
		} else {
			input.type = 'password';
			btn.textContent = revealLabel;
		}
	}

	// ---- Utilities ------------------------------------------------------

	function escapeHtml( s ) {
		return String( null === s || undefined === s ? '' : s ).replace( /[&<>"']/g, ( c ) => (
			{ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]
		) );
	}

	function escapeAttr( s ) {
		return escapeHtml( s );
	}
}() );
