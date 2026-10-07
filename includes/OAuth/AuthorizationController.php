<?php
/**
 * AuthorizationController — /authorize GET (consent) + POST (approve/deny).
 *
 * FR-004..FR-012 + Q1 audience + Q3 always-consent + RFC 9207 iss + PKCE S256.
 *
 * **Consent-surface exception applicability (Constitution §III, Feature-007 exception)**:
 *
 * The Feature-007 consent-surface exception broadens Principle III's
 * `manage_options` baseline for surfaces where a logged-in user consents on
 * their own behalf to issue a credential scoped to their own capabilities. It
 * requires five conditions be satisfied to invoke.
 *
 * F021's `/authorize` matches conditions (1), (2), (4), and (5): it verifies
 * `is_user_logged_in()` (FR-008), binds the issued token to the consenting
 * user's `user_id` (FR-011), cites this exception in this docblock with the
 * driving FR identifiers, and sources every attacker-controllable consent
 * parameter from the server-side `OAuthClients` row via S9 re-validation
 * (see `handle_post` — never trusts hidden inputs).
 *
 * F021 does NOT invoke condition (3) (operator-gated via default-OFF option).
 * Rationale: unlike Feature-007's CLI device grant, F021 is the plugin's
 * primary product surface. Gating it behind an opt-in option would mean the
 * base plugin ships an unusable OAuth server on a fresh install, and every
 * companion plugin (Claude, ChatGPT, Gemini, Copilot, ...) would have to
 * document "also toggle the base plugin's OAuth switch". F021 relies instead
 * on the F024 Phase 10 per-connector `enabled` toggle (default ON) and
 * per-connector `require_admin_approval` toggle (default OFF, FR-024-015)
 * for operator-controlled bounded blast radius on a per-connector granularity.
 *
 * The `/authorize` endpoint is only reachable when: (a) at least one connector
 * profile is registered via a companion plugin, AND (b) that connector's
 * `enabled` setting is true, AND (c) the user is on the approved list when
 * `require_admin_approval` is on. This combination provides the equivalent
 * default-OFF safety property in a more granular form than a single global
 * kill switch would.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Row as ClientRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AuthCodeRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\RateLimiter;
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use AcrossAI_MCP_Manager\Includes\OAuth\CimdRegistry;
use AcrossAI_MCP_Manager\Includes\OAuth\CimdResolver;

defined( 'ABSPATH' ) || exit;

final class AuthorizationController {

	private const NONCE_ACTION = 'acrossai_mcp_manager_oauth_authorize';

	/** @var AuthorizationController|null */
	private static $instance = null;

	/**
	 * Private constructor enforces singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * GET /authorize — validate params, gate on login, render consent.
	 *
	 * @return void
	 */
	public function handle_get(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce applies to POST; GET has no state-changing effect.
		$params = self::sanitize_authorize_params( $_GET );

		self::apply_rate_limit();

		// Validate client + redirect_uri BEFORE any redirect — if these fail we
		// render an inline error page (S9: never redirect to an untrusted URI).
		$client = self::resolve_client_or_die( $params['client_id'], $params['resource'] );
		self::assert_redirect_uri_or_die( $client, $params['redirect_uri'] );

		// Now safe to use redirect-based error reporting.
		if ( 'code' !== $params['response_type'] ) {
			self::redirect_error( $params['redirect_uri'], 'unsupported_response_type', 'Only response_type=code is supported', $params['state'] );
		}

		if ( ! PKCE::is_s256( $params['code_challenge_method'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', 'PKCE S256 required', $params['state'] );
		}

		if ( '' === $params['code_challenge'] || 43 !== strlen( $params['code_challenge'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', 'PKCE code_challenge (43 chars) required', $params['state'] );
		}

		if ( ! self::is_valid_resource( $params['resource'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_target', 'The resource parameter must name a URL on this site.', $params['state'] );
		}

		if ( ! is_user_logged_in() ) {
			// FR-008 — send to wp-login, come back here on success.
			$current_url = self::current_authorize_url();
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		// FR-045 — recommend `state` under WP_DEBUG.
		if ( '' === $params['state'] && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong(
				'/authorize',
				esc_html__( 'Missing state parameter — RECOMMENDED under RFC 9700 §2.1. PKCE still protects code-injection.', 'acrossai-mcp-manager' ),
				'0.1.0'
			);
		}

		// Server-wide connector gate. Prior to the Settings consolidation this
		// block was per-slug and guarded by `'' !== $slug_for_settings`, which
		// meant DCR clients that didn't match Claude/ChatGPT/Grok (e.g. Gemini)
		// bypassed enforcement entirely — a stored client with
		// `connector_slug = ''` reached this branch, failed inference, and
		// then skipped both the enable gate and the admin-approval gate.
		//
		// New model: an empty (or uninferable) slug collapses to the OTHER
		// bucket and rides on the server-wide `allow_other` toggle exposed in
		// the Settings panel as "Any other OAuth-compliant MCP client".
		// Default OFF means Gemini can register via DCR but cannot complete
		// authorize until an admin opts in. Admin approval is likewise
		// server-wide (one toggle applies to every connector on the server).
		$server_id_for_settings = self::server_id_from_client_and_resource( $client, $params['resource'] );
		$slug_for_settings      = (string) $client->connector_slug;
		if ( '' === $slug_for_settings ) {
			$slug_for_settings = self::infer_slug_from_dcr_client( $client );
		}
		$effective_slug = \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSlugDisplay::bucket( $slug_for_settings );

		// Feature 024 review (T018): the `> 0` condition is a fail-open, and is
		// being kept deliberately rather than tightened. Reviewed, not overlooked.
		//
		// `server_id_from_client_and_resource()` returns 0 only when all three
		// of its paths miss: the `resource` resolves to no server, the client
		// row's `server_id` is 0, and the client_id carries no `server-{id}-`
		// prefix. Post-F032 `server_id` is NOT NULL on every client row, so in
		// practice this is reachable only for a pre-F032 row on an install where
		// the resource also fails to resolve — e.g. the companion plugin absent
		// or below its floor.
		//
		// Failing closed there would deny every legacy connection on exactly the
		// installs least able to diagnose it, to defend a setting those installs
		// have no UI to have changed. Failing open keeps them working; the
		// audience check at TokenValidator still constrains what the resulting
		// token can reach. Revisit if the F032 migration is ever declared
		// complete and the legacy paths are removed — at that point 0 means a
		// genuine resolution bug and should be refused.
		if ( $server_id_for_settings > 0 ) {
			if ( ! \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::is_slug_enabled_on_server( $server_id_for_settings, $effective_slug ) ) {
				self::redirect_error( $params['redirect_uri'], 'access_denied', 'This connector is not enabled on this server.', $params['state'] );
			}

			// FR-051 admin bypass preserved from the per-slug model — admins
			// with `manage_options` auto-approve themselves and land in the
			// approved list. Fires `acrossai_mcp_connector_admin_self_bypassed`
			// so forensic reviewers can differentiate self-service bypass rows
			// from explicit-reviewer approvals (SEC-L1 remediation, still
			// applicable under the server-wide storage — approved_by = user_id
			// remains the audit signal).
			//
			// Unknown clients (OTHER bucket) ALWAYS require admin approval,
			// regardless of the server-wide `require_admin_approval` toggle:
			// enabling "Any other OAuth-compliant MCP client" opts the server
			// into accepting unrecognized DCR clients at all, but each such
			// user still needs individual admin sign-off before /authorize
			// completes. Higher-risk category → hard requirement, not opt-in.
			$user_id           = get_current_user_id();
			$requires_approval = \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSlugDisplay::OTHER_SLUG === $effective_slug
				|| \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::is_admin_approval_required( $server_id_for_settings );
			if ( $requires_approval
				&& ! \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::is_user_approved_on_server( $server_id_for_settings, $user_id )
			) {
				if ( user_can( $user_id, 'manage_options' ) ) {
					\AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::add_server_approved_user( $server_id_for_settings, $user_id );

					/**
					 * Fires when an admin self-bypasses the approval gate.
					 * The slug argument is the effective bucket for the client
					 * (real profile slug, or 'other' for unrecognized DCR).
					 *
					 * @param int    $server_id      MCP server row id.
					 * @param string $connector_slug Effective connector slug.
					 * @param int    $user_id        Admin user_id (also approved_by).
					 * @param int    $timestamp      UNIX timestamp.
					 */
					do_action(
						'acrossai_mcp_connector_admin_self_bypassed',
						(int) $server_id_for_settings,
						(string) $effective_slug,
						(int) $user_id,
						time()
					);
				} else {
					\AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::add_server_pending_user( $server_id_for_settings, $user_id );
					self::render_pending_approval( $client, $params );
				}
			}
		}

		// F032 (F015 amendment) — Access Control connection-time gate.
		// Blocks the OAuth authorize flow for users whose roles are not in the
		// F015 allow-list. Prevents the UX pitfall where Claude/etc. show
		// "connected" but every subsequent tool call 403s (invisible to
		// operators). Fires the same observability action as the tool-call
		// gate, but with context = 'oauth_authorize' so operators can differentiate.
		// Fail-open per D19 via `user_has_server_access()` — degrades gracefully
		// if the AC package is absent, server row missing, or manager null.
		if (
			$server_id_for_settings > 0
			// Fail-open per D19: if the mcp-manager AccessControl package is absent
			// (add-on installed without base plugin, or base plugin deactivated),
			// skip the gate rather than PHP-fatal. Matches the comment above.
			&& class_exists( '\\AcrossAI_MCP_Manager\\Includes\\AccessControl\\AcrossAI_MCP_Access_Control' )
		) {
			$authorize_user_id = get_current_user_id();
			$ac                = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
			if ( ! $ac->user_has_server_access( $authorize_user_id, $server_id_for_settings ) ) {
				do_action(
					'acrossai_mcp_access_control_denied',
					$authorize_user_id,
					(string) $server_id_for_settings,
					null,
					'oauth_authorize'
				);
				self::redirect_error(
					$params['redirect_uri'],
					'access_denied',
					'Your account does not have permission to connect to this MCP server. Contact a site administrator to request access.',
					$params['state']
				);
			}
		}

		self::render_consent( $client, $params );
		exit;
	}

	/**
	 * POST /authorize — verify nonce, re-validate every param from DB,
	 * approve → auth code + redirect with code+state+iss; deny → redirect
	 * with access_denied+state+iss.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		self::apply_rate_limit();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			MessagePage::render(
				403,
				__( 'Session expired', 'acrossai-mcp-manager' ),
				array(
					__( 'Your session expired before the connection could be completed. Please start the connection again from your AI app.', 'acrossai-mcp-manager' ),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$params = self::sanitize_authorize_params( $_POST );

		// S9 — re-validate every param from DB, DO NOT trust hidden inputs alone.
		$client = self::resolve_client_or_die( $params['client_id'], $params['resource'] );
		self::assert_redirect_uri_or_die( $client, $params['redirect_uri'] );

		if ( ! PKCE::is_s256( $params['code_challenge_method'] ) || 43 !== strlen( $params['code_challenge'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', 'PKCE S256 required', $params['state'] );
		}
		if ( ! self::is_valid_resource( $params['resource'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_target', 'Invalid resource', $params['state'] );
		}
		if ( ! is_user_logged_in() ) {
			// Unlike GET (which bounces to wp-login and resumes), a POST's
			// form params are gone — a login link could not resume the flow,
			// so render a plain styled page instead.
			MessagePage::render(
				403,
				__( 'Sign-in required', 'acrossai-mcp-manager' ),
				array(
					__( 'You were signed out before the connection could be completed. Please sign in to WordPress, then start the connection again from your AI app.', 'acrossai-mcp-manager' ),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$action = isset( $_POST['authorize_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['authorize_action'] ) ) : '';

		if ( 'deny' === $action ) {
			/**
			 * Action: acrossai_mcp_manager_oauth_authorization_denied
			 */
			do_action( 'acrossai_mcp_manager_oauth_authorization_denied', $params['client_id'], $params['redirect_uri'], 'user_denied' );

			self::redirect_error( $params['redirect_uri'], 'access_denied', 'User denied the authorization request.', $params['state'] );
		}

		if ( 'approve' !== $action ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', 'Missing or invalid authorize_action', $params['state'] );
		}

		// F032 (T038) — resolve server_id from the RFC 8707 `resource` param at
		// authorize time (reuse `server_id_from_client_and_resource` — same
		// route-matching walk already used by the connector-settings gate above).
		// Persist onto the auth_code so TokenController can inherit it at
		// code-exchange without re-parsing the resource URL.
		$server_id_for_auth_code = self::server_id_from_client_and_resource( $client, $params['resource'] );

		// Approve — mint an auth code, redirect with code + state + iss.
		$issued = AuthCodeRepository::create(
			array(
				'client_id'             => $params['client_id'],
				// F032 (T038) — server binding captured at authorize time (FR-011).
				'server_id'             => $server_id_for_auth_code,
				'user_id'               => get_current_user_id(),
				'redirect_uri'          => $params['redirect_uri'],
				'code_challenge'        => $params['code_challenge'],
				'code_challenge_method' => 'S256',
				'scope'                 => 'mcp',
				'resource'              => $params['resource'],
			)
		);

		$callback = add_query_arg(
			array(
				'code'  => rawurlencode( $issued['raw'] ),
				'state' => rawurlencode( $params['state'] ),
				'iss'   => rawurlencode( DiscoveryController::issuer() ),
			),
			$params['redirect_uri']
		);

		wp_redirect( $callback ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect target byte-validated against client's registered URIs.
		exit;
	}

	/**
	 * @param array<string, mixed> $raw Raw input array ($_GET or $_POST).
	 * @return array<string, string>
	 */
	private static function sanitize_authorize_params( array $raw ): array {
		$string_field = static function ( $v ): string {
			return is_scalar( $v ) ? (string) $v : '';
		};

		return array(
			'response_type'         => $string_field( $raw['response_type'] ?? '' ),
			'client_id'             => $string_field( $raw['client_id'] ?? '' ),
			'redirect_uri'          => $string_field( $raw['redirect_uri'] ?? '' ),
			'code_challenge'        => $string_field( $raw['code_challenge'] ?? '' ),
			'code_challenge_method' => $string_field( $raw['code_challenge_method'] ?? '' ),
			'state'                 => $string_field( $raw['state'] ?? '' ),
			'scope'                 => $string_field( $raw['scope'] ?? '' ),
			'resource'              => $string_field( $raw['resource'] ?? '' ),
		);
	}

	/**
	 * Look up the client OR render an inline error page and exit.
	 *
	 * When the CIMD kill switch is ON (filter `acrossai_mcp_manager_cimd_enabled`
	 * returns true — default false), URL-shaped client_ids are routed through
	 * CimdResolver first. Any CIMD failure (untrusted host, fetch error, doc
	 * validation) falls through to the standard DCR lookup below, so this
	 * path is strictly additive and cannot break existing DCR clients.
	 *
	 * Security posture (Feature 007):
	 *   - Kill switch defaults OFF — no behavior change on upgrade.
	 *   - CIMD only ever fetches allowlisted publisher hosts (SSRF gate in
	 *     CimdRegistry).
	 *   - Persisted CIMD clients have `token_endpoint_auth_method='none'`
	 *     enforced at persist time (defense in depth against a compromised
	 *     publisher document flipping to `client_secret_post`).
	 *   - CIMD-persisted clients still hit the Feature-024 per-connector
	 *     `require_admin_approval` gate — CIMD verification is publisher-level,
	 *     not per-user, so operator admin-approval remains the per-user
	 *     authorization boundary.
	 *
	 * @param string $client_id    The client_id from the authorize request.
	 * @param string $resource_url The `?resource=` param (already validated by is_valid_resource).
	 * @return ClientRow
	 * @since 0.5.2 CIMD path added behind acrossai_mcp_manager_cimd_enabled filter.
	 */
	private static function resolve_client_or_die( string $client_id, string $resource_url = '' ): ClientRow {
		/**
		 * Kill switch for the CIMD client-trust path. Defaults false —
		 * operators must explicitly opt in via:
		 *     add_filter( 'acrossai_mcp_manager_cimd_enabled', '__return_true' );
		 *
		 * Rationale: CIMD is a new client-registration model; keeping it
		 * off by default means the upgrade to 0.5.2 changes NO runtime
		 * behavior. Once an operator has reviewed the trusted-publisher
		 * list (`acrossai_mcp_manager_cimd_trusted_publishers`) and is
		 * comfortable with the SSRF gate, they flip this on.
		 *
		 * @param bool   $enabled   Whether CIMD resolution is active.
		 * @param string $client_id The client_id being resolved (informational).
		 * @return bool
		 * @since 0.5.2
		 */
		$cimd_on = (bool) apply_filters( 'acrossai_mcp_manager_cimd_enabled', false, $client_id );

		if ( $cimd_on && CimdRegistry::looks_like_cimd_url( $client_id ) ) {
			$server_id = self::server_id_from_resource( $resource_url );
			$resolved  = CimdResolver::instance()->resolve( $client_id, $server_id );
			if ( null !== $resolved ) {
				return $resolved;
			}
			// CIMD returned null → fall through to standard DCR lookup below.
			// A URL-shaped client_id may also exist as a DCR row (a client that
			// registered its metadata URL via /oauth/register); this preserves
			// that path.
		}

		$client = ClientRepository::find_by_id( $client_id );
		if ( null === $client ) {
			self::render_inline_error(
				400,
				__( 'Invalid client_id.', 'acrossai-mcp-manager' )
			);
		}
		return $client;
	}

	/**
	 * Assert the redirect_uri matches the client's registered set OR
	 * render an inline error page (S9).
	 *
	 * Matching rules:
	 *   1. Exact byte-match (default — HTTPS and every non-loopback URI).
	 *   2. RFC 8252 §7.3 exception for LOOPBACK redirect URIs: the
	 *      authorization server MUST accept any port at request time,
	 *      because native apps (Claude Code CLI, Codex, etc.) obtain an
	 *      ephemeral port from the OS at connection time — the port they
	 *      registered with won't necessarily be the port they call back on.
	 *      Applies only when BOTH the registered and incoming URIs resolve
	 *      to a loopback host (127.0.0.1, ::1, or localhost). Scheme, host,
	 *      path, query, and fragment must still all match exactly.
	 *
	 * Security note: loopback URIs are RFC 8252's designated safe form for
	 * native apps precisely because a callback to `http://127.0.0.1:{port}`
	 * can only reach the process that bound that port on the user's own
	 * machine — port variance doesn't widen the attack surface.
	 *
	 * @param ClientRow $client
	 * @param string    $redirect_uri
	 */
	private static function assert_redirect_uri_or_die( ClientRow $client, string $redirect_uri ): void {
		$registered = $client->decoded_redirect_uris();
		foreach ( $registered as $known ) {
			if ( hash_equals( (string) $known, $redirect_uri ) ) {
				return;
			}
			if ( self::loopback_uris_match_ignoring_port( (string) $known, $redirect_uri ) ) {
				return;
			}
		}
		self::render_inline_error(
			400,
			__( 'Invalid redirect_uri for this client.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * RFC 8252 §7.3 helper — return true iff both URIs are loopback and
	 * differ ONLY in port (scheme + host + path + query + fragment all
	 * match). Callers use this as a fallback comparison after the strict
	 * byte-match fails.
	 *
	 * Loopback hosts recognized (per RFC 8252 §7.3): `127.0.0.1`, `::1`,
	 * and the string literal `localhost`. Host comparison is
	 * case-insensitive (DNS); scheme comparison is case-insensitive per
	 * RFC 3986; path/query/fragment comparison is byte-exact.
	 *
	 * A non-loopback URI on either side short-circuits to false — the
	 * exception is strictly scoped so a badly-configured whitelist can't
	 * accidentally loosen matching for HTTPS callbacks.
	 *
	 * @param string $known    URI as registered.
	 * @param string $incoming URI from the current /authorize request.
	 * @return bool
	 */
	private static function loopback_uris_match_ignoring_port( string $known, string $incoming ): bool {
		$loopback_hosts = array( '127.0.0.1', '::1', 'localhost' );

		$pa = wp_parse_url( $known );
		$pb = wp_parse_url( $incoming );
		if ( ! is_array( $pa ) || ! is_array( $pb ) ) {
			return false;
		}

		// wp_parse_url() returns IPv6 hosts wrapped in brackets (`[::1]`).
		// Strip them so the comparison matches the bare RFC 4291 form.
		$normalize_host = static function ( string $h ): string {
			$h = strtolower( $h );
			if ( '' !== $h && '[' === $h[0] && ']' === substr( $h, -1 ) ) {
				$h = substr( $h, 1, -1 );
			}
			return $h;
		};
		$ha             = $normalize_host( (string) ( $pa['host'] ?? '' ) );
		$hb             = $normalize_host( (string) ( $pb['host'] ?? '' ) );
		if ( '' === $ha || $ha !== $hb ) {
			return false;
		}
		if ( ! in_array( $ha, $loopback_hosts, true ) ) {
			return false;
		}

		if ( strtolower( (string) ( $pa['scheme'] ?? '' ) ) !== strtolower( (string) ( $pb['scheme'] ?? '' ) ) ) {
			return false;
		}
		if ( ! hash_equals( (string) ( $pa['path'] ?? '' ), (string) ( $pb['path'] ?? '' ) ) ) {
			return false;
		}
		if ( ! hash_equals( (string) ( $pa['query'] ?? '' ), (string) ( $pb['query'] ?? '' ) ) ) {
			return false;
		}
		if ( ! hash_equals( (string) ( $pa['fragment'] ?? '' ), (string) ( $pb['fragment'] ?? '' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * True iff $resource_url names a URL on this site (or loopback for dev).
	 *
	 * @param string $resource_url Candidate resource URL from `?resource=` param.
	 * @return bool
	 */
	private static function is_valid_resource( string $resource_url ): bool {
		if ( '' === $resource_url ) {
			return false;
		}

		$parts = wp_parse_url( $resource_url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );

		if ( in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
			return in_array( $scheme, array( 'http', 'https' ), true );
		}

		if ( 'https' !== $scheme ) {
			// Allow http for local dev where home_url() may be http.
			$site_parts = wp_parse_url( home_url() );
			if ( ! is_array( $site_parts ) || strtolower( (string) ( $site_parts['scheme'] ?? '' ) ) !== $scheme ) {
				return false;
			}
		}

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return hash_equals( $site_host, $host );
	}

	/**
	 * Redirect back to the client with an OAuth error.
	 *
	 * @param string $redirect_uri
	 * @param string $error
	 * @param string $description
	 * @param string $state
	 * @return void
	 */
	private static function redirect_error( string $redirect_uri, string $error, string $description, string $state ): void {
		$args = array(
			'error'             => rawurlencode( $error ),
			'error_description' => rawurlencode( $description ),
			'iss'               => rawurlencode( DiscoveryController::issuer() ),
		);
		if ( '' !== $state ) {
			$args['state'] = rawurlencode( $state );
		}

		wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URI already byte-validated against client's registered set.
		exit;
	}

	/**
	 * Render an inline error page and exit — used ONLY when we cannot trust
	 * the redirect_uri (unknown client or mismatched URI). Routes through
	 * the shared styled MessagePage template; still strictly inline (S9 —
	 * never redirects to an unvalidated URI).
	 *
	 * @param int    $status
	 * @param string $message
	 * @return never
	 */
	private static function render_inline_error( int $status, string $message ): void {
		MessagePage::render(
			$status,
			__( 'Connection problem', 'acrossai-mcp-manager' ),
			array(
				$message,
				__( 'The AI application sent an invalid connection request. Please try connecting again from your AI app.', 'acrossai-mcp-manager' ),
			)
		);
	}

	/**
	 * Q3 — render consent template on every request. No memoization.
	 *
	 * @param ClientRow             $client
	 * @param array<string, string> $params Sanitized authorize params. Consumed by the required template.
	 * @return void
	 */
	private static function render_consent( ClientRow $client, array $params ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $params consumed by required template via `require`.
		$profile = '' !== $client->connector_slug
			? ConnectorProfileRegistry::instance()->get_profile( $client->connector_slug )
			: null;

		$branding = null !== $profile
			? $profile->get_consent_branding()
			: array(
				'heading'             => sprintf(
					/* translators: %s: client name */
					__( '%s wants to connect to your site', 'acrossai-mcp-manager' ),
					'' !== $client->client_name ? $client->client_name : __( 'An application', 'acrossai-mcp-manager' )
				),
				'subtitle'            => __( 'This will allow the application to access the MCP tools you have exposed on this server.', 'acrossai-mcp-manager' ),
				'permissions_bullets' => array(),
			);

		$connector_icon = null !== $profile ? $profile->get_icon_url() : '';
		$client_name    = $client->client_name;
		$current_user   = wp_get_current_user();
		$post_url       = home_url( '/authorize' );

		CacheHeaders::send_no_store();
		header( 'Content-Type: text/html; charset=utf-8' );

		$template = plugin_dir_path( __DIR__ ) . '../templates/oauth/consent.php';
		if ( ! file_exists( $template ) ) {
			// Fallback path relative to includes/OAuth/.
			$template = dirname( __DIR__, 2 ) . '/templates/oauth/consent.php';
		}
		require $template;
	}

	/**
	 * Reconstruct the current /authorize URL for wp_login redirect_to.
	 *
	 * @return string
	 */
	private static function current_authorize_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/authorize';
		return home_url( $path );
	}

	/**
	 * Apply the 60/IP/60s rate-limit shared by /authorize and /token.
	 *
	 * @return void
	 */
	private static function apply_rate_limit(): void {
		$check = RateLimiter::check( 'authorize', RateLimiter::client_ip(), 60, 60 );
		if ( $check instanceof \WP_Error ) {
			// /authorize is a browser surface — render the styled HTML page
			// (the /token endpoint keeps its own JSON 429). Retry-After and
			// the 429 status are preserved verbatim.
			MessagePage::render(
				429,
				__( 'Too many attempts', 'acrossai-mcp-manager' ),
				array(
					__( 'You have made too many authorization attempts. Please wait 60 seconds and try again from your AI app.', 'acrossai-mcp-manager' ),
				),
				array( 'headers' => array( 'Retry-After: 60' ) )
			);
		}
	}

	/**
	 * F024 helper — figure out which MCP server row a client + resource belong to.
	 *
	 * Two paths:
	 *   1. Admin-generated client: parse `server-{id}` prefix from client_id.
	 *      This is the Q2 client_id format (`server-{server_id}-{slug}-{rand8}`)
	 *      shipped in F021 Phase 3.
	 *   2. DCR-registered client (or any client with no server prefix):
	 *      compare the resource URL's path against the `server_route_namespace +
	 *      server_route` of every enabled server row until we find a match.
	 *
	 * Returns 0 if we can't resolve, in which case the ConnectorSettings gate
	 * falls back to "no enforcement" and lets the request proceed.
	 *
	 * @param ClientRow $client      The OAuth client row.
	 * @param string    $resource_url The resource URL from the authorize params.
	 * @return int
	 */
	private static function server_id_from_client_and_resource( ClientRow $client, string $resource_url ): int {
		// Path 0 (F032, canonical): `server_id` is a first-class NOT NULL column on the client row.
		// This is the authoritative source of truth post-F032. The two legacy fallback paths below
		// exist only as defense-in-depth for the theoretical case of a row created between F032
		// deployment and its migration completing (should not happen — FR-028 gate blocks that).
		// Historical: pre-F032 this method parsed the `server-{id}-` prefix from client_id which
		// broke silently for DCR clients (random hex client_id) — see the git log at
		// AuthorizationController.php:459.
		// Path 0 (RFC 8707): the `resource` the client is asking to be authorized
		// FOR wins over the server it happened to REGISTER against. Authorize
		// already requires a valid `resource` (is_valid_resource rejects empty),
		// and a single MCP host may hold connectors to several servers on this
		// site. Deferring to $client->server_id here meant the connector-enabled,
		// admin-approval and Access-Control gates below were all evaluated
		// against the wrong server row, and the auth code was stamped with it —
		// so a second connector could never authenticate against its own server.
		$resource_server_id = self::server_id_from_resource( $resource_url );
		if ( $resource_server_id > 0 ) {
			return $resource_server_id;
		}

		// Path 1 (F032): `server_id` is a first-class NOT NULL column on the
		// client row — the registration binding, used when the resource does not
		// resolve (e.g. mcp-manager absent).
		if ( (int) $client->server_id > 0 ) {
			return (int) $client->server_id;
		}

		// Path 2 (legacy fallback): parse from admin-generated client_id.
		if ( preg_match( '/\Aserver-(\d+)-/', (string) $client->client_id, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * Resolve `?resource=` URL → MCP server row id, without needing a
	 * ClientRow. Extracted from Path 2 above so CIMD (which resolves the
	 * client BEFORE it has a Row) can share the mapping.
	 *
	 * Add-on dependency guard: requires mcp-manager's MCPServer\Query. When
	 * absent (base plugin missing or pre-Feature-021), returns 0 so callers
	 * can degrade cleanly instead of PHP-fatal.
	 *
	 * @param string $resource_url The `?resource=` query parameter (already validated by is_valid_resource).
	 * @return int Server row id, or 0 if not resolvable.
	 * @since 0.5.2 (F007)
	 */
	public static function server_id_from_resource( string $resource_url ): int {
		if ( '' === $resource_url ) {
			return 0;
		}
		$resource_path = (string) wp_parse_url( $resource_url, PHP_URL_PATH );
		if ( '' === $resource_path ) {
			return 0;
		}
		$resource_path = rtrim( $resource_path, '/' );

		if ( ! class_exists( '\\AcrossAI_MCP_Manager\\Includes\\Database\\MCPServer\\Query' ) ) {
			return 0;
		}

		$servers = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query( array( 'number' => 100 ) );
		if ( empty( $servers ) ) {
			return 0;
		}

		foreach ( $servers as $server_row ) {
			$namespace = '' !== $server_row->server_route_namespace ? (string) $server_row->server_route_namespace : 'mcp';
			$route     = (string) $server_row->server_route;
			if ( '' === $route ) {
				continue;
			}
			$server_url  = rest_url( trailingslashit( $namespace ) . $route );
			$server_path = rtrim( (string) wp_parse_url( $server_url, PHP_URL_PATH ), '/' );
			if ( '' === $server_path ) {
				continue;
			}
			if ( $resource_path === $server_path || 0 === strpos( $resource_path, $server_path . '/' ) ) {
				return (int) $server_row->id;
			}
		}
		return 0;
	}

	/**
	 * F024 helper — for a DCR-registered client (connector_slug empty),
	 * walk the registered profiles asking `matches_dcr_client` until one
	 * claims it. Returns the slug of the first claiming profile or ''.
	 *
	 * @param ClientRow $client The client row being consulted.
	 * @return string
	 */
	private static function infer_slug_from_dcr_client( ClientRow $client ): string {
		$profiles = ConnectorProfileRegistry::instance()->get_profiles();
		if ( empty( $profiles ) ) {
			return '';
		}
		$redirect_uris = $client->decoded_redirect_uris();
		$name          = (string) $client->client_name;
		foreach ( $profiles as $profile ) {
			if ( $profile->matches_dcr_client( $name, $redirect_uris ) ) {
				return $profile->get_slug();
			}
		}
		return '';
	}

	/**
	 * F024 FR-024-015 — render a lightweight "pending admin approval"
	 * page instead of the consent screen. No form, no nonce (nothing to
	 * submit). The user just closes this and waits for the admin. Routed
	 * through the shared MessagePage template; shows the connector's icon
	 * when the client resolves to a registered profile.
	 *
	 * @param ClientRow             $client Client row.
	 * @param array<string, string> $params Sanitized authorize params.
	 * @return never
	 */
	private static function render_pending_approval( ClientRow $client, array $params ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $params reserved for a richer template later.
		$profile = '' !== $client->connector_slug
			? ConnectorProfileRegistry::instance()->get_profile( $client->connector_slug )
			: null;

		$extra = array();
		if ( null !== $profile && '' !== $profile->get_icon_url() ) {
			$extra['icon_url'] = $profile->get_icon_url();
		}

		MessagePage::render(
			200,
			__( 'Waiting for admin approval', 'acrossai-mcp-manager' ),
			array(
				__( 'This connector requires an administrator to approve your access before you can complete the connection.', 'acrossai-mcp-manager' ),
				__( 'Your request has been recorded. You can close this window — the administrator will notify you when access is granted, and you can retry the connection from your AI client at that point.', 'acrossai-mcp-manager' ),
			),
			$extra
		);
	}
}
