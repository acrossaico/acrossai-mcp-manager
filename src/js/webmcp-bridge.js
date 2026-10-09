/**
 * F093 — WebMCP bridge.
 *
 * Publishes the selected MCP server's tools to an in-browser agent. Loads on
 * wp-admin screens only, and only when the beta is on and a server resolves.
 *
 * FIVE RULES, EACH LEARNED THE HARD WAY SOMEWHERE ELSE
 *
 * 1. Detect, never define. A polyfill that replaces `document.modelContext`
 *    makes NATIVE agents blind — ChatGPT's browser and Chrome's origin-trial
 *    consumers read the host object. Reading is safe; defining is not. There
 *    is no polyfill here at all in v1.
 *
 * 2. `document.modelContext` only. `navigator.modelContext` was the
 *    pre-standard location and was removed in Chrome 152. Two of the four
 *    WebMCP plugins on wp.org still register only through it and therefore do
 *    nothing on a current browser.
 *
 * 3. `registerTool` one at a time, awaited. Never `provideContext`. ChatGPT's
 *    `modelContext` is frozen and implements only `registerTool`, so a batch
 *    call silently no-ops against it.
 *
 * 4. One AbortController PER TOOL, and unregistration only through `abort()`.
 *    Both Laravel's package and this plan arrived at per-tool independently;
 *    the best WordPress plugin shares one controller and aborts it on
 *    `pagehide`, which never fires on an SPA route change — so in the block
 *    editor its previous screen's tools stay registered forever.
 *
 * 5. Diff, do not churn. On a route change, unregister what left, register
 *    what is new, and LEAVE IDENTICAL TOOLS ALONE. Blanket re-registration
 *    tears down a tool the agent may be mid-call on, for no benefit when
 *    nothing changed.
 *
 * @package
 */

( function() {
	'use strict';

	const cfg = window.acrossaiWebmcpBridge || {};

	/**
	 * The host API, or null. Read-only probe — see rule 1.
	 *
	 * @return {Object|null} The model context object, or null when absent.
	 */
	function hostContext() {
		if (
			typeof document !== 'undefined' &&
			document.modelContext &&
			typeof document.modelContext.registerTool === 'function'
		) {
			return document.modelContext;
		}
		return null;
	}

	const ctx = hostContext();
	if ( ! ctx || ! cfg.restUrl ) {
		// No agent can see anything here. Do nothing at all rather than
		// registering into a void.
		return;
	}

	// name -> { controller, fingerprint }. The fingerprint is what makes the
	// diff in rule 5 possible: it decides "identical" without comparing whole
	// schemas on every navigation.
	const registered = new Map();

	let nonce = cfg.nonce || '';
	let syncing = false;

	/**
	 * Same-origin guard. The bridge talks to its own site and nothing else —
	 * a tool whose endpoint could be redirected off-origin would hand an
	 * agent's authenticated session to a third party.
	 *
	 * @param {string} url Candidate URL.
	 * @return {boolean} True when the URL is same-origin.
	 */
	function sameOrigin( url ) {
		try {
			return new URL( url, window.location.href ).origin === window.location.origin;
		} catch {
			return false;
		}
	}

	/**
	 * Fetch against our own REST routes, retrying once on a stale nonce.
	 *
	 * WP nonces last 12–24h. An agent working a long-open block-editor tab
	 * WILL outlive one, and without this it would start getting 403s
	 * mid-session with no visible cause.
	 *
	 * @param {string}  path    Route below the WebMCP base.
	 * @param {Object}  options Fetch options.
	 * @param {boolean} retry   Internal: whether a nonce refresh may be tried.
	 * @return {Promise<Object>} Parsed JSON body.
	 */
	async function api( path, options = {}, retry = true ) {
		const url = cfg.restUrl + path;
		if ( ! sameOrigin( url ) ) {
			throw new Error( 'Refusing a non-same-origin WebMCP request.' );
		}

		const response = await fetch( url, {
			...options,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
				...( options.headers || {} ),
			},
		} );

		if ( 403 === response.status && retry && cfg.nonceUrl ) {
			const refreshed = await fetch( cfg.nonceUrl, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce },
			} );
			if ( refreshed.ok ) {
				const body = await refreshed.json();
				if ( body && body.nonce ) {
					nonce = body.nonce;
					return api( path, options, false );
				}
			}
		}

		if ( ! response.ok ) {
			throw new Error( 'WebMCP request failed: ' + response.status );
		}

		return response.json();
	}

	/**
	 * Cheap identity for a tool definition.
	 *
	 * @param {Object} tool Tool descriptor from the server.
	 * @return {string} Fingerprint.
	 */
	function fingerprint( tool ) {
		return JSON.stringify( [ tool.slug, tool.label, tool.description, tool.inputSchema ] );
	}

	/**
	 * Build the `execute` closure for one tool.
	 *
	 * Honours the per-tool abort signal: a tool withdrawn mid-call must not
	 * resolve afterwards and look live.
	 *
	 * @param {Object}      tool   Tool descriptor.
	 * @param {AbortSignal} signal Per-tool signal.
	 * @return {Function} The execute handler.
	 */
	function makeExecute( tool, signal ) {
		return async function( input ) {
			if ( signal.aborted ) {
				throw new Error( 'This tool is no longer available on this screen.' );
			}

			const body = await api(
				'/execute',
				{
					method: 'POST',
					// The slug travels in the BODY, never the path: every slug
					// contains a slash and Apache's AllowEncodedSlashes Off
					// 404s an encoded one before PHP ever runs.
					body: JSON.stringify( { slug: tool.slug, input: input || {} } ),
					signal,
				},
			);

			return body && undefined !== body.result ? body.result : body;
		};
	}

	/**
	 * Register one tool, awaited. See rule 3.
	 *
	 * @param {Object} tool Tool descriptor.
	 * @return {Promise<void>}
	 */
	async function register( tool ) {
		const controller = new AbortController();

		await ctx.registerTool(
			{
				name: tool.name,
				title: tool.label,
				description: tool.description,
				inputSchema: tool.inputSchema,
				execute: makeExecute( tool, controller.signal ),
			},
			{ signal: controller.signal },
		);

		registered.set( tool.name, { controller, fingerprint: fingerprint( tool ) } );
	}

	/**
	 * Withdraw one tool. Abort is the only unregistration mechanism the API
	 * offers — there is no `unregisterTool`.
	 *
	 * @param {string} name Tool name.
	 */
	function withdraw( name ) {
		const entry = registered.get( name );
		if ( entry ) {
			entry.controller.abort();
			registered.delete( name );
		}
	}

	/**
	 * Reconcile what is registered against what the server now publishes.
	 *
	 * This is rule 5. Three outcomes per tool: gone (withdraw), new
	 * (register), unchanged (leave strictly alone).
	 *
	 * @return {Promise<void>}
	 */
	async function sync() {
		if ( syncing ) {
			// Guards double registration when two navigation events land
			// together — the symptom would be a tool registered twice and
			// withdrawn once.
			return;
		}
		syncing = true;

		try {
			let tools = [];
			try {
				const body = await api( '/tools', { method: 'GET' } );
				tools = ( body && body.tools ) || [];
			} catch {
				// A refusal is a legitimate answer: the beta may be off, the
				// server disabled, or this user outside its access rule. In
				// every case the correct state is "no tools", so fall through
				// with an empty list and let the diff withdraw everything.
				tools = [];
			}

			const wanted = new Map();
			tools.forEach( function( tool ) {
				if ( tool && tool.name ) {
					wanted.set( tool.name, tool );
				}
			} );

			// Gone.
			Array.from( registered.keys() ).forEach( function( name ) {
				if ( ! wanted.has( name ) ) {
					withdraw( name );
				}
			} );

			// New or changed. Awaited one at a time — see rule 3.
			for ( const [ name, tool ] of wanted ) {
				const existing = registered.get( name );

				if ( existing && existing.fingerprint === fingerprint( tool ) ) {
					continue; // Identical. Leave it alone.
				}
				if ( existing ) {
					withdraw( name );
				}

				try {
					await register( tool );
				} catch {
					// One bad tool must not stop the rest registering.
					registered.delete( name );
				}
			}
		} finally {
			syncing = false;
		}
	}

	/**
	 * Fire `sync` on SPA navigation as well as real page loads.
	 *
	 * `pagehide` alone is not enough: the block editor moves between screens
	 * without a page unload, so a listener bound only to unload would leave
	 * the previous screen's tools registered indefinitely. History methods
	 * are wrapped because they emit no event of their own.
	 */
	function watchNavigation() {
		const emit = function() {
			window.dispatchEvent( new Event( 'acrossai-webmcp-navigated' ) );
		};

		[ 'pushState', 'replaceState' ].forEach( function( method ) {
			const original = window.history[ method ];
			if ( 'function' !== typeof original ) {
				return;
			}
			window.history[ method ] = function() {
				const out = original.apply( this, arguments );
				emit();
				return out;
			};
		} );

		window.addEventListener( 'popstate', emit );
		window.addEventListener( 'acrossai-webmcp-navigated', function() {
			sync();
		} );

		// Final teardown. Belt and braces alongside the per-tool signals.
		window.addEventListener( 'pagehide', function() {
			Array.from( registered.keys() ).forEach( withdraw );
		} );
	}

	watchNavigation();
	sync();
}() );
