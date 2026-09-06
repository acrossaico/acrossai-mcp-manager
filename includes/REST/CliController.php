<?php
/**
 * REST controller for CLI-tool authentication flow under namespace
 * `acrossai-mcp-manager/v1`.
 *
 * Singleton + private ctor (A2). Zero hooks in the constructor (A1 /
 * FR-021) — Main::define_public_hooks wires `rest_api_init` via Loader.
 *
 * S2 documented exemption: `__return_true` is acceptable on `/health`
 * (public read), `/auth/start` (bounded mutation; no PII), `/auth/status`
 * (read-only), and `/auth/exchange` (body's `code` IS the auth
 * credential). Defense-in-depth: FR-015 Content-Type allow-list (Q2)
 * rejects missing/unknown headers BEFORE field validation, and Q4
 * session-token server-binding eliminates the cross-server enumeration
 * vector. S8 memory capture queued post-implementation.
 *
 * @package AcrossAI_MCP_Manager\Includes\REST
 */

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use AcrossAI_MCP_Manager\Includes\Utilities\SiteSlug;
use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class CliController {

	const REST_NAMESPACE           = 'acrossai-mcp-manager/v1';
	const AUTH_TRANSIENT_PREFIX    = 'acrossai_cli_auth_';
	const SESSION_TRANSIENT_PREFIX = 'acrossai_session_';
	const AUTH_CODE_TTL            = 300;
	const SESSION_TOKEN_TTL        = 600;
	const APP_PASSWORD_TTL_INFO    = 2592000;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance() instead.
	 */
	private function __construct() {}

	/**
	 * Register the 5 REST routes on `rest_api_init`. Wired via Loader.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_auth_start' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'server_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_auth_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'server' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/servers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_servers' ),
				'permission_callback' => array( $this, 'verify_session_token' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/exchange',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_auth_exchange' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * `verify_session_token` permission callback for GET /servers.
	 *
	 * Reads `Authorization: Bearer <token>` (with REDIRECT_HTTP_AUTHORIZATION
	 * fallback for Apache+CGI). Validates the session transient as a Q4
	 * `array{user_id, server_id}` payload. On success, sets current user AND
	 * stashes `_bound_server_id` on the request for the endpoint body.
	 *
	 * @param WP_REST_Request $request Inbound REST request.
	 * @return true|WP_Error
	 */
	public function verify_session_token( WP_REST_Request $request ) {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return new WP_Error( 'rest_unauthorized', 'Missing Bearer token.', array( 'status' => 401 ) );
		}

		$token = trim( substr( $header, 7 ) );
		if ( '' === $token || strlen( $token ) > 64 ) {
			return new WP_Error( 'rest_unauthorized', 'Malformed Bearer token.', array( 'status' => 401 ) );
		}

		// Per Q4 — payload is array{user_id: int, server_id: string}.
		$payload = get_transient( self::SESSION_TRANSIENT_PREFIX . $token );
		if ( ! is_array( $payload )
			|| ! isset( $payload['user_id'], $payload['server_id'] )
			|| ! is_numeric( $payload['user_id'] )
		) {
			return new WP_Error( 'rest_unauthorized', 'Invalid or expired session token.', array( 'status' => 401 ) );
		}

		wp_set_current_user( (int) $payload['user_id'] );
		$request->set_param( '_bound_server_id', (string) $payload['server_id'] );
		return true;
	}

	/**
	 * Q2 Content-Type allow-list helper (per FR-015 / research R9).
	 *
	 * Returns `null` to continue; returns an `invalid_request` 400 response
	 * to abort. Called as Step 0 of `handle_auth_start` and
	 * `handle_auth_exchange`.
	 *
	 * @param WP_REST_Request $request Inbound REST request.
	 */
	private function check_content_type( WP_REST_Request $request ): ?WP_REST_Response {
		$ct_info = $request->get_content_type();
		$value   = strtolower( (string) ( $ct_info['value'] ?? '' ) );
		if ( 'application/json' !== $value && 'application/x-www-form-urlencoded' !== $value ) {
			return CacheHeaders::apply_to_rest_response( new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 ) );
		}
		return null;
	}

	/**
	 * GET /health — plugin discovery + site_slug (FR-001).
	 *
	 * @param WP_REST_Request $request Inbound REST request (unused).
	 */
	public function handle_health( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		// Global response — safe to edge-cache. Explicit opt-in so intermediaries
		// (LSC, CDNs) can cache without ambiguity. See DEC-OAUTH-DONOTCACHEPAGE-PATTERN.
		return CacheHeaders::apply_public_cache_to_rest_response(
			new WP_REST_Response(
				array(
					'plugin_installed' => true,
					'plugin_active'    => true,
					'version'          => defined( 'ACROSSAI_MCP_MANAGER_VERSION' ) ? (string) ACROSSAI_MCP_MANAGER_VERSION : '0.0.0',
					'site_slug'        => SiteSlug::get(),
				),
				200
			)
		);
	}

	/**
	 * POST /auth/start — issue auth_code + auth_url (FR-002).
	 *
	 * @param WP_REST_Request $request Inbound REST request.
	 */
	public function handle_auth_start( WP_REST_Request $request ): WP_REST_Response {
		// Step 0 — Q2 Content-Type allow-list.
		$ct_err = $this->check_content_type( $request );
		if ( null !== $ct_err ) {
			return $ct_err;
		}

		$server_id = sanitize_text_field( (string) $request->get_param( 'server_id' ) );
		if ( '' === $server_id ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}

		try {
			$auth_code = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[acrossai-mcp] random_bytes failed in handle_auth_start: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'server_error' ), 500 );
		}

		$ok = set_transient(
			self::AUTH_TRANSIENT_PREFIX . $auth_code,
			array(
				'server_id'     => $server_id,
				'status'        => 'pending',
				'user_id'       => null,
				'session_token' => null,
				'created_at'    => time(),
			),
			self::AUTH_CODE_TTL
		);

		if ( false === $ok ) {
			return new WP_REST_Response( array( 'error' => 'server_error' ), 500 );
		}

		$auth_url = FrontendAuth::get_base_url() . '?' . http_build_query(
			array(
				'action' => 'cli_auth',
				'code'   => $auth_code,
				'server' => $server_id,
			)
		);

		// Per-request auth code — MUST NOT cache. See DEC-OAUTH-DONOTCACHEPAGE-PATTERN.
		return CacheHeaders::apply_to_rest_response(
			new WP_REST_Response(
				array(
					'auth_code'  => $auth_code,
					'auth_url'   => $auth_url,
					'expires_in' => self::AUTH_CODE_TTL,
				),
				200
			)
		);
	}

	/**
	 * GET /auth/status — poll approval state (FR-003).
	 *
	 * Q4 oracle defense: server-id mismatch returns `{approved: false}`,
	 * NOT 404 — indistinguishable from a real pending state.
	 *
	 * @param WP_REST_Request $request Inbound REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_auth_status( WP_REST_Request $request ) {
		$code   = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$server = sanitize_text_field( (string) $request->get_param( 'server' ) );

		$payload = get_transient( self::AUTH_TRANSIENT_PREFIX . $code );
		if ( false === $payload || ! is_array( $payload ) ) {
			return new WP_Error( 'auth_code_not_found', 'Authorization code not found.', array( 'status' => 404 ) );
		}

		if ( 'approved' === ( $payload['status'] ?? '' )
			&& hash_equals( (string) ( $payload['server_id'] ?? '' ), $server )
		) {
			// Per-code session token — MUST NOT cache.
			return CacheHeaders::apply_to_rest_response(
				new WP_REST_Response(
					array(
						'approved' => true,
						'token'    => (string) ( $payload['session_token'] ?? '' ),
					),
					200
				)
			);
		}

		// Even the "still pending" response varies per-code and per-poll — MUST NOT cache
		// (otherwise a cached `false` would keep serving after the auth flips to `true`).
		return CacheHeaders::apply_to_rest_response( new WP_REST_Response( array( 'approved' => false ), 200 ) );
	}

	/**
	 * GET /servers — single-server inventory (Q4 binding).
	 *
	 * @param WP_REST_Request $request Inbound REST request (carries `_bound_server_id`).
	 */
	public function handle_servers( WP_REST_Request $request ): WP_REST_Response {
		$bound_server_id = (string) $request->get_param( '_bound_server_id' );
		$rows            = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $bound_server_id,
				'is_enabled'  => 1,
				'number'      => 1,
			)
		);

		if ( empty( $rows ) ) {
			return new WP_REST_Response( array( 'servers' => array() ), 200 );
		}

		$row     = $rows[0];
		$user_id = get_current_user_id();
		$ns      = (string) $row->server_route_namespace;
		$route   = (string) $row->server_route;

		// Feature 015 — Access Control v2 adoption. Route through the plugin-scoped
		// wrapper (never the vendor's v1-API ::instance() static, which does not
		// exist in v2). Fail-open when the vendor package is unavailable.
		$ac = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
		if ( $ac->is_available() ) {
			$manager = $ac->get_manager();
			$slug    = $ns . '/' . $route;
			if ( null !== $manager && ! $manager->user_has_access( $user_id, 'acrossai-mcp-manager', $slug ) ) {
				// FR-026 observability hook fires BEFORE the silent-empty-list return.
				do_action( 'acrossai_mcp_access_control_denied', $user_id, $slug, null, 'cli_servers' );
				return new WP_REST_Response( array( 'servers' => array() ), 200 );
			}
		}

		/*
		 * `id` field carries the SLUG string — matches the client-facing
		 * identifier the CLI passes via `--server=<slug>` and displays back
		 * to the user. The `@acrossai/mcp-manager` CLI matches with
		 * `servers.find( s => s.id === serverId )` (see
		 * `src/serverValidator.js:24`), so `id` MUST be the slug, not the
		 * integer PK. The `slug` alias is preserved as a redundant / forward-
		 * compat field: if any future consumer wants an explicit `slug` key,
		 * it's already there.
		 *
		 * The integer PK is intentionally NOT exposed in the response — no
		 * documented consumer needs it, and hiding it keeps the API's
		 * identifier surface single-string (slug) instead of dual (int+slug).
		 */
		// Per-session inventory (bound to the session token) — MUST NOT cache.
		return CacheHeaders::apply_to_rest_response(
			new WP_REST_Response(
				array(
					'servers' => array(
						array(
							'id'          => (string) $row->server_slug,
							'slug'        => (string) $row->server_slug,
							'name'        => (string) $row->server_name,
							'description' => (string) $row->description,
							'enabled'     => (bool) $row->is_enabled,
							'version'     => (string) $row->server_version,
							'namespace'   => $ns,
							'route'       => $route,
							'mcp_url'     => rest_url( $ns . '/' . $route ),
						),
					),
				),
				200
			)
		);
	}

	/**
	 * POST /auth/exchange — issue Application Password (FR-005/006/007).
	 *
	 * 8-step validation chain + Step 0 Q2 Content-Type allow-list.
	 *
	 * @param WP_REST_Request $request Inbound REST request.
	 */
	public function handle_auth_exchange( WP_REST_Request $request ): WP_REST_Response {
		// Step 0 — Q2 Content-Type allow-list.
		$ct_err = $this->check_content_type( $request );
		if ( null !== $ct_err ) {
			return $ct_err;
		}

		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );

		// Step 1 — read transient.
		$payload = get_transient( self::AUTH_TRANSIENT_PREFIX . $code );
		if ( false === $payload || ! is_array( $payload ) ) {
			return $this->error( 'invalid_code', 400 );
		}

		// Step 2 — status check.
		if ( 'approved' !== ( $payload['status'] ?? '' ) ) {
			return $this->error( 'not_approved', 400 );
		}

		// Step 3 — user existence.
		$stored_user_id = (int) ( $payload['user_id'] ?? 0 );
		$user           = get_userdata( $stored_user_id );
		if ( false === $user || ! ( $user instanceof \WP_User ) ) {
			return $this->error( 'invalid_user', 400 );
		}

		// Step 4 — WP-Apps capability.
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			// Audit row NOT written for this path.
			return $this->error( 'not_supported', 501 );
		}

		// Step 5 — server_id present.
		$request_server_id = sanitize_title( (string) $request->get_param( 'server_id' ) );
		if ( '' === $request_server_id ) {
			return $this->error( 'missing_server', 400 );
		}

		// Step 6 — server_id matches transient (hash_equals).
		$stored_server_id = (string) ( $payload['server_id'] ?? '' );
		if ( ! hash_equals( $stored_server_id, $request_server_id ) ) {
			// Transients NOT deleted — legitimate retry possible.
			return $this->error( 'server_mismatch', 400 );
		}

		// Step 7 — server resolves to an enabled row.
		$server_rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $request_server_id,
				'is_enabled'  => 1,
				'number'      => 1,
			)
		);
		if ( empty( $server_rows ) ) {
			return $this->error( 'invalid_server', 403 );
		}
		$resolved_server_slug = (string) $server_rows[0]->server_slug;

		// Step 8 (success path) — create + delete + audit + respond.
		try {
			// Q3 — uniqueness suffix from the first 8 hex chars of the raw code.
			$app_pwd_name = 'AcrossAI MCP Manager CLI - ' . $resolved_server_slug . ' - ' . substr( $code, 0, 8 );
			$result       = \WP_Application_Passwords::create_new_application_password(
				$stored_user_id,
				array( 'name' => $app_pwd_name )
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[acrossai-mcp] WP-Apps create threw: ' . $e->getMessage() );
			return $this->error( 'server_error', 500 );
		}

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[acrossai-mcp] WP-Apps create returned WP_Error: ' . $result->get_error_message() );
			return $this->error( 'server_error', 500 );
		}

		// WP-Apps returns positional [raw_password, record_array].
		$raw_password = isset( $result[0] ) ? (string) $result[0] : '';
		$record       = isset( $result[1] ) && is_array( $result[1] ) ? $result[1] : array();
		$uuid         = (string) ( $record['uuid'] ?? '' );

		// Single-use enforcement — delete both transients.
		delete_transient( self::AUTH_TRANSIENT_PREFIX . $code );
		$stored_session_token = (string) ( $payload['session_token'] ?? '' );
		if ( '' !== $stored_session_token ) {
			delete_transient( self::SESSION_TRANSIENT_PREFIX . $stored_session_token );
		}

		// Best-effort audit.
		Recorder::record_success(
			$stored_user_id,
			$request_server_id,
			hash( 'sha256', $code ),
			$uuid
		);

		// Application Password in response body — MUST NOT cache. Full defense
		// via CacheHeaders (DONOTCACHEPAGE + no-store + Pragma).
		$resp = CacheHeaders::apply_to_rest_response(
			new WP_REST_Response(
				array(
					'app_password' => $raw_password,
					'username'     => (string) $user->user_login,
					'user_id'      => (int) $stored_user_id,
					'expires_in'   => self::APP_PASSWORD_TTL_INFO,
					'server_id'    => $request_server_id,
				),
				200
			)
		);
		$resp->header( 'Pragma', 'no-cache' );
		return $resp;
	}

	/**
	 * Public static method called by FrontendAuth::handle_approve (FR-008).
	 *
	 * Reads the pending E1 transient, generates a session token, writes the
	 * Q4-bound E2 transient as `array{user_id, server_id}`, calls Recorder.
	 *
	 * @param string $auth_code Raw authorization code from the CLI.
	 * @param int    $user_id   Approving admin's user ID.
	 * @return bool true on first valid call; false on absent transient or already-approved state.
	 */
	public static function approve_auth_code( string $auth_code, int $user_id ): bool {
		$payload = get_transient( self::AUTH_TRANSIENT_PREFIX . $auth_code );
		if ( false === $payload || ! is_array( $payload ) ) {
			return false;
		}
		if ( 'pending' !== ( $payload['status'] ?? '' ) ) {
			return false;
		}

		try {
			$session_token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[acrossai-mcp] random_bytes failed in approve_auth_code: ' . $e->getMessage() );
			return false;
		}

		$payload['status']        = 'approved';
		$payload['user_id']       = $user_id;
		$payload['session_token'] = $session_token;
		set_transient( self::AUTH_TRANSIENT_PREFIX . $auth_code, $payload, self::AUTH_CODE_TTL );

		// Q4 — session token transient value is array{user_id, server_id}.
		set_transient(
			self::SESSION_TRANSIENT_PREFIX . $session_token,
			array(
				'user_id'   => (int) $user_id,
				'server_id' => (string) ( $payload['server_id'] ?? '' ),
			),
			self::SESSION_TOKEN_TTL
		);

		Recorder::record_approved( $user_id, (string) ( $payload['server_id'] ?? '' ), hash( 'sha256', $auth_code ) );

		return true;
	}

	/**
	 * Read-only peek at the pending auth-code transient — returns the bound
	 * server_id ONLY when the transient exists, is well-formed, and has
	 * status === 'pending'. Returns null in every other case.
	 *
	 * Added 2026-06-30 to fix SEC-001 / S9 (consent-surface displayed-state
	 * MUST come from the authoritative store, not the URL). Consumed by
	 * FrontendAuth::handle_cli_auth() to source the displayed server slug.
	 *
	 * Pure stateless read — no transient writes, no audit-log writes, no
	 * error_log, no exceptions. Idempotent: calling N times returns identical
	 * values and changes no state. Applies B11 transient-defensive
	 * triple-check (generalized).
	 *
	 * See contracts/cli-controller-peek-pending-server.md (Phase 7 spec) +
	 * docs/memory/PROJECT_CONTEXT.md §S9 + docs/memory/BUGS.md §B11.
	 *
	 * @param string $auth_code Raw authorization code from the CLI's URL.
	 * @return string|null Bound server_id for pending codes; null otherwise.
	 */
	public static function peek_pending_server( string $auth_code ): ?string {
		if ( '' === $auth_code ) {
			return null;
		}
		$payload = get_transient( self::AUTH_TRANSIENT_PREFIX . $auth_code );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		if ( ! isset( $payload['status'], $payload['server_id'] ) ) {
			return null;
		}
		if ( 'pending' !== $payload['status'] ) {
			return null;
		}
		if ( ! is_string( $payload['server_id'] ) || '' === $payload['server_id'] ) {
			return null;
		}
		return $payload['server_id'];
	}

	/**
	 * Compose an `invalid_*` JSON error envelope.
	 *
	 * @param string $code   Error code (e.g. `invalid_code`).
	 * @param int    $status HTTP status.
	 */
	private function error( string $code, int $status ): WP_REST_Response {
		return CacheHeaders::apply_to_rest_response( new WP_REST_Response( array( 'error' => $code ), $status ) );
	}
}
