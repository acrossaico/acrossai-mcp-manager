<?php
/**
 * F024 admin REST endpoints for per-server, per-connector operations.
 *
 * Endpoints (all under `acrossai-mcp-manager/v1`):
 *   POST /oauth/connector-settings      — save enabled + require_admin_approval
 *   POST /oauth/revoke-client-tokens    — revoke every token for a client_id
 *   POST /oauth/delete-client           — revoke tokens + delete client row
 *   POST /oauth/revoke-connector-tokens — mass-revoke all tokens for a
 *                                          connector on a server
 *   POST /oauth/approve-pending-consent — admin approves a user's pending consent
 *
 * Every endpoint gated on `manage_options` + `X-WP-Nonce` (`wp_rest`).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 * @since 0.1.0 (F024)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSlugDisplay;
use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Query as ConnectorApprovedUsersQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;

defined( 'ABSPATH' ) || exit;

final class ConnectorAdminController {

	private const REST_NAMESPACE = 'acrossai-mcp-manager/v1';

	/** @var ConnectorAdminController|null */
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
	 * Register the 5 F024 admin routes. Wired by Main.php on `rest_api_init`.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/connector-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-client-tokens',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_client_tokens' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/delete-client',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_delete_client' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// Per-grant revoke — one authorization grant (token_family_id) on one
		// server. Powers the per-grant rows on the Connections panel, where two
		// claude.ai accounts share a DCR client_id but never a family.
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-grant',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_grant' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-connector-tokens',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_connector_tokens' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// Server-neutral revoke — operator-visible "Revoke from all servers" nuclear button.
		// Intentional carve-out from D31 (F032 per-server invariant). Requires only client_id;
		// bypasses server_id validation by design.
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-client-tokens-all-servers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_client_tokens_all_servers' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/approve-pending-consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_approve_pending_consent' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// Server-wide settings — introduced when the AI Connectors admin UI
		// moved from per-connector sub-tabs to a single top-level Settings
		// panel. Payload:
		// { server_id, enabled_slugs[], allow_other, require_admin_approval }
		// Cascade-revokes tokens for any slug transitioning enabled → disabled.
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/server-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_server_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// Server-wide counterparts to the per-slug approve/deny/revoke
		// endpoints below. New UI writes with the server-wide sentinel slug
		// (ConnectorApprovedUsersQuery::SERVER_WIDE_SLUG); read paths query
		// for any slug (see is_user_approved_on_server).
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/approve-server-pending',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_approve_server_pending' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/deny-server-pending',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_deny_server_pending' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-server-approval',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_server_approval' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// "Revoke all connections on this server" button on the Settings
		// panel — nuclear, revokes every non-revoked token on the server
		// regardless of connector.
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-server-tokens',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_server_tokens' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// F032 — Deny a pending consent request WITHOUT approving.
		// Removes the user from the pending list; the user must re-attempt the
		// connect flow (which will re-add them to pending, or immediately succeed
		// if require_admin_approval was turned off in the meantime).
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/deny-pending-consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_deny_pending_consent' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// F032 — Revoke an existing approval. Deletes the row from the
		// ConnectorApprovedUsers table. Fires `acrossai_mcp_connector_user_approval_revoked`
		// action; the default listener (`cascade_revoke_tokens_on_approval_revoked`)
		// then revokes the user's active OAuth tokens for that (server, connector) pair.
		// Third-party listeners can opt out via the `acrossai_mcp_connector_revoke_tokens_on_approval_revoked` filter.
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-user-approval',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_user_approval' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	/**
	 * Shared permission callback — manage_options + wp_rest nonce.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return bool|\WP_Error
	 */
	public function admin_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'acrossai_mcp_oauth_forbidden', __( 'Insufficient permissions.', 'acrossai-mcp-manager' ), array( 'status' => 403 ) );
		}
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'acrossai_mcp_oauth_bad_nonce', __( 'Invalid or missing nonce.', 'acrossai-mcp-manager' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * FR-024-020 — save (server_id, slug) settings. When `enabled` flips
	 * from true to false, mass-revoke every non-revoked token for that
	 * connector on that server.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_save_settings( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$new_settings = array(
			'enabled'                => (bool) $request->get_param( 'enabled' ),
			'require_admin_approval' => (bool) $request->get_param( 'require_admin_approval' ),
		);

		$previous = ConnectorSettings::get( $server_id, $slug );
		ConnectorSettings::save( $server_id, $slug, $new_settings );

		$revoked_count = 0;
		if ( $previous['enabled'] && ! $new_settings['enabled'] ) {
			$revoked_count = self::mass_revoke_connector_tokens( $server_id, $slug, 'connector_disabled' );
		}

		return new \WP_REST_Response(
			array(
				'settings'      => $new_settings,
				'revoked_count' => $revoked_count,
			),
			200
		);
	}

	/**
	 * Persist the server-wide settings record. Payload:
	 *   { server_id, enabled_slugs[], allow_other, require_admin_approval }
	 *
	 * On any slug transitioning enabled → disabled, cascade-revoke every
	 * non-revoked token belonging to a client of that connector on this
	 * server. Mirrors the per-connector `handle_save_settings` behavior at
	 * the server level. The `other` bucket has its own revoke path
	 * (`mass_revoke_other_tokens`) because those clients aren't attributable
	 * to a registered profile.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_save_server_settings( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		if ( $server_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$raw_slugs        = (array) $request->get_param( 'enabled_slugs' );
		$new_enabled      = array();
		$registered_slugs = array();
		foreach ( ConnectorProfileRegistry::instance()->get_profiles() as $profile ) {
			$registered_slugs[] = $profile->get_slug();
		}
		foreach ( $raw_slugs as $s ) {
			if ( ! is_string( $s ) ) {
				continue;
			}
			$s = strtolower( $s );
			if ( in_array( $s, $registered_slugs, true ) ) {
				$new_enabled[] = $s;
			}
		}
		$new_enabled = array_values( array_unique( $new_enabled ) );

		$new_settings = array(
			'enabled_slugs'          => $new_enabled,
			'allow_other'            => (bool) $request->get_param( 'allow_other' ),
			'require_admin_approval' => (bool) $request->get_param( 'require_admin_approval' ),
		);

		$previous = ConnectorSettings::get_server_settings( $server_id );
		ConnectorSettings::save_server_settings( $server_id, $new_settings );

		$revoked_count = 0;
		foreach ( array_diff( $previous['enabled_slugs'], $new_settings['enabled_slugs'] ) as $removed_slug ) {
			$revoked_count += self::mass_revoke_connector_tokens( $server_id, (string) $removed_slug, 'connector_disabled' );
		}
		if ( $previous['allow_other'] && ! $new_settings['allow_other'] ) {
			$revoked_count += self::mass_revoke_other_tokens( $server_id, 'connector_disabled' );
		}

		return new \WP_REST_Response(
			array(
				'settings'      => $new_settings,
				'revoked_count' => $revoked_count,
			),
			200
		);
	}

	/**
	 * Server-wide approve — move a user from the server pending list into
	 * the approved list with the SERVER_WIDE_SLUG sentinel.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_approve_server_pending( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		$user_id   = (int) $request->get_param( 'user_id' );
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id or user_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}
		ConnectorSettings::remove_server_pending_user( $server_id, $user_id );
		ConnectorSettings::add_server_approved_user( $server_id, $user_id );
		return new \WP_REST_Response( array( 'approved_user_id' => $user_id ), 200 );
	}

	/**
	 * Server-wide deny — remove a user from the pending list without
	 * approving. Symmetric with `handle_approve_server_pending`.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_deny_server_pending( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		$user_id   = (int) $request->get_param( 'user_id' );
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id or user_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}
		ConnectorSettings::remove_server_pending_user( $server_id, $user_id );
		return new \WP_REST_Response( array( 'denied_user_id' => $user_id ), 200 );
	}

	/**
	 * Server-wide revoke approval — delete every approval row for
	 * (server_id, user_id) regardless of slug (server-wide sentinel rows +
	 * legacy per-connector rows). Cascade-revokes the user's tokens on this
	 * server via the same per-connector cascade helper.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_server_approval( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		$user_id   = (int) $request->get_param( 'user_id' );
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id or user_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$deleted = ConnectorApprovedUsersQuery::instance()->revoke_server_wide( $server_id, $user_id );

		// Fire the same observability action as the per-connector revoke path
		// so downstream listeners (default cascade + third parties) run once
		// per registered profile — the cascade below de-dupes across profiles
		// because tokens are keyed by client_id.
		foreach ( ConnectorProfileRegistry::instance()->get_profiles() as $profile ) {
			do_action(
				'acrossai_mcp_connector_user_approval_revoked',
				$server_id,
				$profile->get_slug(),
				$user_id,
				(int) get_current_user_id()
			);
		}

		return new \WP_REST_Response(
			array(
				'revoked_user_id' => $user_id,
				'rows_deleted'    => $deleted,
			),
			200
		);
	}

	/**
	 * Nuclear "Revoke all connections on this server" button on the Settings
	 * panel. Revokes every non-revoked token for every client on this
	 * server, regardless of connector.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_server_tokens( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		if ( $server_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$total = 0;
		foreach ( ClientRepository::find_all_for_server( $server_id ) as $client_row ) {
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id( (string) $client_row->client_id, (int) $client_row->server_id );
			foreach ( $revoked_ids as $token_id ) {
				do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'server_nuclear_revoke' );
			}
			$total += count( $revoked_ids );
		}

		return new \WP_REST_Response( array( 'revoked_count' => $total ), 200 );
	}

	/**
	 * FR-024-021 — revoke every token for a client_id on a specific server.
	 *
	 * F032 (T035) — requires both `server_id` and `client_id` in body. Cross-server
	 * mismatch fires 4-arg `acrossai_mcp_oauth_cross_server_attempted` observability
	 * action BEFORE returning WP_Error 403 (D19 fail-open + SEC-032-001 remediation).
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_client_tokens( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		$client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
		if ( $server_id <= 0 || '' === $client_id ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id or client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		// F032 (T035) — cross-server-safe composite lookup. Null return signals
		// mismatch OR missing client — either way, respond with an opaque 403 so
		// cross-server existence is not disclosed.
		$client_row = ClientsQuery::instance()->find_by_client_id_and_server_id( $client_id, $server_id );
		if ( null === $client_row ) {
			// SEC-032-001 remediation: 4-arg observability action — MUST NOT include
			// the actual owning server_id, which would recreate the oracle F032 exists
			// to close. Listeners that need the owning server for forensics can query
			// the DB directly from within their handler.
			do_action(
				'acrossai_mcp_oauth_cross_server_attempted',
				$client_id,
				$server_id,
				get_current_user_id(),
				time()
			);
			return new \WP_Error(
				'acrossai_mcp_oauth_cross_server',
				__( 'This client does not belong to the specified server.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		// Feature 010: optional user_id scope. When present, revoke only that
		// user's tokens for this client/server; omit for full-client behavior
		// (backward compatible with pre-F010 callers).
		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id > 0 ) {
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id_and_user_id( $client_id, $server_id, $user_id );
		} else {
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id( $client_id, $server_id );
		}
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'admin_revoke' );
		}

		return new \WP_REST_Response( array( 'revoked_count' => count( $revoked_ids ) ), 200 );
	}

	/**
	 * Revoke a single authorization grant (token family) on one server.
	 *
	 * Unlike the client_id endpoints there is no clients-table ownership row to
	 * pre-validate, so no opaque-403 / `acrossai_mcp_oauth_cross_server_attempted`
	 * dance: the revoke SQL is itself server-scoped, a (family, server) mismatch
	 * simply affects 0 rows, and family UUIDs are unguessable — nothing about
	 * other servers is disclosed by `revoked_count: 0`.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_grant( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		$family_id = (string) $request->get_param( 'token_family_id' );

		if ( $server_id <= 0 || 1 !== preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $family_id ) ) {
			return new \WP_Error( 'invalid_request', __( 'Missing or invalid server_id or token_family_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_family_id_and_server_id( $family_id, $server_id );
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'admin_revoke_grant' );
		}

		return new \WP_REST_Response( array( 'revoked_count' => count( $revoked_ids ) ), 200 );
	}

	/**
	 * FR-024-022 — revoke tokens then delete the client row.
	 *
	 * F032 (T036) — same server_id validation + 4-arg observability fire on 403
	 * as `handle_revoke_client_tokens` (T035).
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete_client( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		$client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
		if ( $server_id <= 0 || '' === $client_id ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id or client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$client_row = ClientsQuery::instance()->find_by_client_id_and_server_id( $client_id, $server_id );
		if ( null === $client_row ) {
			do_action(
				'acrossai_mcp_oauth_cross_server_attempted',
				$client_id,
				$server_id,
				get_current_user_id(),
				time()
			);
			return new \WP_Error(
				'acrossai_mcp_oauth_cross_server',
				__( 'This client does not belong to the specified server.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		// Feature 010: optional user_id scope. When present, revoke only that
		// user's tokens; keep the client row alive if any OTHER user still has
		// non-revoked tokens on it (so we don't unilaterally break someone
		// else's connection to a shared client). Omit for full-client delete.
		$user_id      = (int) $request->get_param( 'user_id' );
		$revoked_only = false;
		if ( $user_id > 0 ) {
			$revoked_ids     = TokensQuery::instance()->revoke_by_client_id_and_user_id( $client_id, $server_id, $user_id );
			$remaining_users = TokensQuery::instance()->get_active_user_ids_by_client_id_and_server_id( $client_id, $server_id );
			$revoked_only    = ! empty( $remaining_users );
		} else {
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id( $client_id, $server_id );
		}

		$reason = $user_id > 0 ? 'admin_delete_user_connection' : 'admin_delete_client';
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, $reason );
		}

		$client_deleted = false;
		if ( ! $revoked_only ) {
			ClientsQuery::instance()->delete_by_id( (int) $client_row->id );
			$client_deleted = true;
		}

		return new \WP_REST_Response(
			array(
				'revoked_count'  => count( $revoked_ids ),
				'client_deleted' => $client_deleted,
				'revoked_only'   => $revoked_only,
			),
			200
		);
	}

	/**
	 * Server-neutral revoke — kill every non-revoked token for this client_id across
	 * EVERY server on this site. Intentional operator-visible carve-out from F032's
	 * per-server invariant (D31): the "Revoke from all servers" nuclear button.
	 *
	 * Contrast with `handle_revoke_client_tokens` (per-server, 4-arg observability
	 * on cross-server mismatch). This endpoint is *always* server-neutral and MUST NOT
	 * fire the `acrossai_mcp_oauth_cross_server_attempted` action — the operator is
	 * DELIBERATELY invoking a cross-server operation, not attempting a bypass.
	 *
	 * Fires per-token `acrossai_mcp_manager_oauth_token_revoked` (existing shape) +
	 * one aggregate `acrossai_mcp_oauth_client_revoked_across_all_servers` signal.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_client_tokens_all_servers( \WP_REST_Request $request ) {
		$client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
		if ( '' === $client_id ) {
			return new \WP_Error( 'invalid_request', __( 'Missing client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_client_id_across_all_servers( $client_id );
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'admin_revoke_all_servers' );
		}

		/**
		 * Fires once per "Revoke from all servers" admin action. Distinct from the
		 * per-server signal so operators can differentiate scoped vs global revoke.
		 *
		 * @param string $client_id          The client_id that was revoked globally.
		 * @param int    $revoked_token_count Total tokens revoked across all servers.
		 * @param int    $user_id            Admin performing the action.
		 * @param int    $timestamp          UNIX timestamp.
		 */
		do_action(
			'acrossai_mcp_oauth_client_revoked_across_all_servers',
			$client_id,
			count( $revoked_ids ),
			get_current_user_id(),
			time()
		);

		return new \WP_REST_Response(
			array(
				'revoked_count' => count( $revoked_ids ),
				'scope'         => 'all_servers',
			),
			200
		);
	}

	/**
	 * FR-024-016 — nuclear revoke: kill every token for a connector on a server.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_connector_tokens( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$revoked_count = self::mass_revoke_connector_tokens( $server_id, $slug, 'admin_nuclear_revoke' );

		return new \WP_REST_Response( array( 'revoked_count' => $revoked_count ), 200 );
	}

	/**
	 * FR-024-023 — admin approves a specific user's pending consent.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_approve_pending_consent( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing user_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		ConnectorSettings::remove_pending_user( $server_id, $slug, $user_id );
		ConnectorSettings::add_approved_user( $server_id, $slug, $user_id );

		return new \WP_REST_Response( array( 'approved_user_id' => $user_id ), 200 );
	}

	/**
	 * Deny a pending consent request WITHOUT adding to the approved list.
	 * Symmetric counterpart to `handle_approve_pending_consent`. The user
	 * must re-attempt the connect flow from their AI host if they want to try again.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_deny_pending_consent( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing user_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		ConnectorSettings::remove_pending_user( $server_id, $slug, $user_id );

		return new \WP_REST_Response( array( 'denied_user_id' => $user_id ), 200 );
	}

	/**
	 * Revoke an existing approval for a (server, connector, user) triple.
	 * Deletes the row from the ConnectorApprovedUsers table. Does NOT touch
	 * existing OAuth tokens for the user — the operator should use the
	 * Connections panel's Revoke/Delete buttons if they also want to force
	 * an immediate disconnect. This separation keeps approval-lifecycle
	 * (who is allowed to connect) and token-lifecycle (who is currently
	 * connected) as independent concerns.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_user_approval( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing user_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$deleted = ConnectorApprovedUsersQuery::instance()->revoke( $server_id, $slug, $user_id );

		/**
		 * Fires immediately after a connector user approval is revoked.
		 *
		 * The default listener (`ConnectorAdminController::cascade_revoke_tokens_on_approval_revoked`)
		 * is registered by `Main::define_admin_hooks()` and revokes every active
		 * OAuth token the user holds for this (server, connector) pair. To opt out
		 * of the token cascade without removing the listener entirely, hook the
		 * `acrossai_mcp_connector_revoke_tokens_on_approval_revoked` filter and
		 * return false. Third-party plugins can also `add_action()` on this hook
		 * to layer additional side effects (audit log, email notification, etc.).
		 *
		 * @param int $server_id       MCP server row id.
		 * @param string $connector_slug Connector profile slug.
		 * @param int $user_id         WP user id whose approval was revoked.
		 * @param int $revoked_by      Admin's user id (get_current_user_id() at call site).
		 */
		do_action(
			'acrossai_mcp_connector_user_approval_revoked',
			$server_id,
			$slug,
			$user_id,
			(int) get_current_user_id()
		);

		return new \WP_REST_Response(
			array(
				'revoked_user_id' => $user_id,
				'was_approved'    => $deleted,
			),
			200
		);
	}

	/**
	 * Default listener for `acrossai_mcp_connector_user_approval_revoked`.
	 * Cascades the approval revoke into an active-token revoke for the same
	 * (server, connector, user) triple — enumerates every client matching the
	 * connector profile (admin-generated + DCR) and calls
	 * `TokensQuery::revoke_by_user_and_server_and_client_ids()`.
	 *
	 * Opt-out via the `acrossai_mcp_connector_revoke_tokens_on_approval_revoked`
	 * filter (returns true by default). Fires per-token
	 * `acrossai_mcp_manager_oauth_token_revoked` with reason `approval_revoked`
	 * for downstream observability.
	 *
	 * @param int    $server_id       MCP server row id.
	 * @param string $connector_slug  Connector profile slug.
	 * @param int    $user_id         User id whose approval was revoked.
	 * @param int    $revoked_by      Admin who triggered the revoke.
	 * @return void
	 */
	public static function cascade_revoke_tokens_on_approval_revoked( int $server_id, string $connector_slug, int $user_id, int $revoked_by ): void {
		/**
		 * Opt-out filter. Return false to skip the token cascade entirely — the
		 * approval-revoke DB row is still deleted, but the user's existing tokens
		 * stay active until they expire naturally or an admin uses the
		 * Connections panel's Revoke/Delete buttons.
		 *
		 * @param bool   $should_revoke   Default true.
		 * @param int    $server_id       MCP server row id.
		 * @param string $connector_slug  Connector profile slug.
		 * @param int    $user_id         User id.
		 * @param int    $revoked_by      Admin id.
		 */
		if ( ! apply_filters( 'acrossai_mcp_connector_revoke_tokens_on_approval_revoked', true, $server_id, $connector_slug, $user_id, $revoked_by ) ) {
			return;
		}

		if ( $server_id <= 0 || '' === $connector_slug || $user_id <= 0 ) {
			return;
		}

		// Enumerate every client on this server that belongs to this connector
		// profile — admin-generated (prefix-matched) + DCR (profile-matched via
		// `matches_dcr_client`). Delegates to the shared enumeration helper
		// (V1 refactor) so this cascade + `mass_revoke_connector_tokens` share
		// the same enumeration semantic — no risk of drift.
		$client_ids = array();
		foreach ( self::enumerate_connector_clients( $server_id, $connector_slug ) as $client_row ) {
			$client_ids[] = (string) $client_row->client_id;
		}

		if ( empty( $client_ids ) ) {
			return;
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_user_and_server_and_client_ids( $user_id, $server_id, $client_ids );
		foreach ( $revoked_ids as $token_id ) {
			/**
			 * Per-token observability action fired once per row transitioned to
			 * `revoked=1`. Reason `approval_revoked` is a stable enum;
			 * downstream loggers can differentiate this cascade from admin
			 * revoke / delete / nuclear paths via the reason string.
			 */
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'approval_revoked' );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Validate + extract server_id + connector_slug from the request body.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return array{0: int, 1: string}|\WP_Error
	 */
	private static function validate_server_and_slug( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		if ( $server_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}
		$slug = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $request->get_param( 'connector_slug' ) ) );
		if ( '' === $slug || ! preg_match( '/\A[a-z0-9-]{1,64}\z/', $slug ) ) {
			return new \WP_Error( 'invalid_request', __( 'Missing or invalid connector_slug.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}
		if ( null === ConnectorProfileRegistry::instance()->get_profile( $slug ) ) {
			return new \WP_Error( 'not_found', __( 'Connector profile is not registered.', 'acrossai-mcp-manager' ), array( 'status' => 404 ) );
		}
		return array( $server_id, $slug );
	}

	/**
	 * Basic sanitizer for client_id parameter.
	 *
	 * @param string $client_id Raw input.
	 * @return string
	 */
	private static function sanitize_client_id( string $client_id ): string {
		$clean = preg_replace( '/[^a-zA-Z0-9\-_]/', '', $client_id );
		return null === $clean ? '' : $clean;
	}

	/**
	 * Mass-revoke every non-revoked token belonging to any client on this
	 * (server, connector) pair. Fires `token_revoked` per row with the
	 * supplied reason.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param string $reason    Reason string for the observability action.
	 * @return int Total revoked.
	 */
	private static function mass_revoke_connector_tokens( int $server_id, string $slug, string $reason ): int {
		$client_rows = self::enumerate_connector_clients( $server_id, $slug );
		if ( empty( $client_rows ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $client_rows as $client_row ) {
			// F032 (T037) — always pass server_id from the current row (matches
			// this loop's server scope by construction — admin_clients is already
			// server-scoped and dcr_clients is filtered above).
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id( (string) $client_row->client_id, (int) $client_row->server_id );
			foreach ( $revoked_ids as $token_id ) {
				do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, $reason );
			}
			$total += count( $revoked_ids );
		}

		return $total;
	}

	/**
	 * Mass-revoke every non-revoked token belonging to a client in the
	 * "other" bucket on this server — i.e. any client whose connector_slug
	 * is empty OR points to a slug that isn't a registered profile. Called
	 * when the admin toggles the "Any other OAuth-compliant MCP client"
	 * checkbox from on → off in the Settings panel.
	 *
	 * @param int    $server_id MCP server row id.
	 * @param string $reason    Observability reason string.
	 * @return int Total tokens revoked.
	 */
	private static function mass_revoke_other_tokens( int $server_id, string $reason ): int {
		if ( $server_id <= 0 ) {
			return 0;
		}

		$known = array();
		foreach ( ConnectorProfileRegistry::instance()->get_profiles() as $profile ) {
			$known[] = $profile->get_slug();
		}

		$total = 0;
		foreach ( ClientRepository::find_all_for_server( $server_id ) as $client_row ) {
			$slug = (string) $client_row->connector_slug;
			if ( '' !== $slug && in_array( $slug, $known, true ) ) {
				continue;
			}
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id( (string) $client_row->client_id, (int) $client_row->server_id );
			foreach ( $revoked_ids as $token_id ) {
				do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, $reason );
			}
			$total += count( $revoked_ids );
		}
		return $total;
	}

	/**
	 * F032 (V1 refactor) — enumerate every OAuth client on a server that
	 * belongs to a connector profile. Combines the admin-generated set
	 * (`server-{id}-{slug}-{rand}` prefix — already server-scoped by the
	 * dedicated Query helper) with the DCR set filtered by
	 * `AbstractConnectorProfile::matches_dcr_client`.
	 *
	 * Extracted from two prior duplicated call sites — `mass_revoke_connector_tokens`
	 * and `cascade_revoke_tokens_on_approval_revoked`. Any new caller that
	 * needs the "all clients for this (server, connector)" set MUST route
	 * through here so the DCR filter stays a single-source-of-truth.
	 *
	 * Returns an empty array (no fatal) if the connector profile is not
	 * registered — matches the pre-refactor early-return behavior of both
	 * original call sites.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return array<int, object> Client rows (Row instances from ClientsQuery).
	 */
	private static function enumerate_connector_clients( int $server_id, string $connector_slug ): array {
		if ( $server_id <= 0 || '' === $connector_slug ) {
			return array();
		}

		$profile = ConnectorProfileRegistry::instance()->get_profile( $connector_slug );
		if ( null === $profile ) {
			return array();
		}

		$admin_clients = ClientsQuery::instance()->find_admin_clients_for_server_connector( $server_id, $connector_slug );

		// F032 (T037) — restrict DCR-side enumeration to this server ONLY.
		// Pre-F032, find_dcr_clients() unfiltered returned rows across every
		// server sharing a matching DCR profile; iterating those and calling
		// the unscoped revoke_by_client_id() would silently revoke tokens on
		// other servers (cross-server leak — the P1 F032 fix).
		$dcr_clients = array();
		foreach ( ClientsQuery::instance()->find_dcr_clients( $server_id ) as $dcr_row ) {
			if ( $profile->matches_dcr_client( (string) $dcr_row->client_name, $dcr_row->decoded_redirect_uris() ) ) {
				$dcr_clients[] = $dcr_row;
			}
		}

		return array_merge( $admin_clients, $dcr_clients );
	}
}
