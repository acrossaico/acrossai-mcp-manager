<?php
/**
 * Admin-issued OAuth token controller for the n8n per-server tab (Feature 013).
 *
 * Mints an admin-scoped OAuth access token bound to a per-server durable
 * "n8n" admin client, with operator-selectable TTL (1/7/30/90 days). This
 * is a compensating credential for n8n's currently-broken OAuth2 DCR
 * implementation (n8n#22103, #23712; upstream fix in #22405 not yet
 * shipped).
 *
 * Route: POST /wp-json/acrossai-mcp-manager/v1/servers/{server_id}/n8n/bearer/token
 * Namespace: reused from mcp-manager — do NOT introduce a new namespace.
 *
 * PRE-EXISTING ACTION SHAPES (recorded by T003 grep) — do NOT drift (A3):
 *   - acrossai_mcp_manager_oauth_token_revoked: `(int $token_id, string $reason)`.
 *     Existing reason enum: user_deleted / client_regenerated /
 *     family_reuse_detected / refresh_rotation / server_nuclear_revoke /
 *     admin_revoke / admin_revoke_all_servers / approval_revoked /
 *     connector_disabled. New value added by this feature:
 *     `n8n_admin_regenerated`.
 *   - acrossai_mcp_manager_oauth_token_issued: `(int $token_id, string
 *     $client_id, int $user_id, string $connector_slug, array $metadata = [])`.
 *     Existing 4 positional args unchanged; new optional 5th `$metadata`
 *     array carries `[flow, server_id, ttl_seconds, expires_at, regenerated]`
 *     for this feature. Existing 4-arg subscribers unaffected.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes/OAuth
 * @since      0.10.0
 */

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\N8nTab;
use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class AdminTokenController {

	private const REST_NAMESPACE = 'acrossai-mcp-manager/v1';
	private const CONNECTOR_SLUG = 'n8n';
	private const ALLOWED_TTLS   = array( 86400, 604800, 2592000, 7776000 );
	private const DEFAULT_TTL    = 2592000;

	/** @var self|null */
	private static ?self $instance = null;

	/**
	 * Audit-log an admin-issued n8n token.
	 *
	 * Listens on `acrossai_mcp_manager_oauth_token_issued` and ignores every
	 * flow but its own. Silent unless an operator opts in via
	 * `ACROSSAI_MCP_MANAGER_AUDIT_LOG` or `WP_DEBUG_LOG`.
	 *
	 * Logs identifiers and timings only — never the token or its digest.
	 *
	 * @param int|string           $token_id       Issued token row id.
	 * @param string               $client_id      Owning client.
	 * @param int                  $user_id        Consenting user.
	 * @param string               $connector_slug Connector bucket.
	 * @param array<string, mixed> $metadata       Issuance context.
	 */
	public static function maybe_log_token_issuance( $token_id, $client_id, $user_id, $connector_slug, $metadata = array() ): void {
		unset( $token_id, $client_id, $connector_slug ); // Read from $metadata below.

		if ( ! is_array( $metadata ) || ( $metadata['flow'] ?? '' ) !== 'n8n_admin' ) {
			return;
		}

		$enabled = ( defined( 'ACROSSAI_MCP_MANAGER_AUDIT_LOG' ) && ACROSSAI_MCP_MANAGER_AUDIT_LOG )
				|| ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
		if ( ! $enabled ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in audit trail; carries identifiers and timings only, never the token.
			sprintf(
				'[audit] n8n_token_issued user=%d server=%d ttl=%d expires=%d regen=%s',
				(int) $user_id,
				(int) ( $metadata['server_id'] ?? 0 ),
				(int) ( $metadata['ttl_seconds'] ?? 0 ),
				(int) ( $metadata['expires_at'] ?? 0 ),
				! empty( $metadata['regenerated'] ) ? 'true' : 'false'
			)
		);
	}

	/**
	 * Returns the singleton instance.
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
	 * Registers the admin-issued n8n bearer-token route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/servers/(?P<server_id>\d+)/n8n/bearer/token',
			array(
				'methods'             => 'POST', // C6 — POST-only prevents CSRF-via-image-tag.
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'server_id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'ttl_seconds' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => self::DEFAULT_TTL,
						'enum'              => self::ALLOWED_TTLS,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Fail-fast permission gate. Order (cheapest → most expensive):
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error
	 *   1. nonce (401 rest_cookie_invalid_nonce)
	 *   2. manage_options (403 rest_forbidden)
	 *   3. N8nTab::is_enabled() (403 rest_n8n_disabled)
	 *
	 * The companion had a fourth step here — a Freemius premium check returning
	 * 403 rest_n8n_premium_required. F095 made n8n free, so it is gone. Three
	 * gates, no licence lane.
	 */
	public function permission_callback( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Cookie check failed.', 'acrossai-mcp-manager' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		if ( ! N8nTab::is_enabled() ) {
			return new \WP_Error(
				'rest_n8n_disabled',
				__( 'n8n integration is disabled.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Issues a bearer token for the requested server.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$server_id   = (int) $request->get_param( 'server_id' );
		$ttl_seconds = (int) $request->get_param( 'ttl_seconds' );

		// Resolve server row — 404 with distinct code so UI can distinguish
		// "server was deleted" from "you don't have access".
		$server_item = MCPServerQuery::instance()->get_item( $server_id );
		if ( ! $server_item ) {
			return new \WP_Error(
				'rest_server_not_found',
				__( 'Server not found.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}
		$server = is_array( $server_item ) ? $server_item : (array) $server_item;

		// Compute audience URL (RFC 8707 binding).
		$mcp_url = AbstractConnectorProfile::mcp_url_for_server( $server );

		try {
			// Find or lazily create the durable "n8n" admin client for this
			// server. Shared across all admins on the server.
			$client = ClientRepository::find_admin_client( $server_id, self::CONNECTOR_SLUG );

			if ( null === $client ) {
				$new_client_id = self::admin_client_id( $server_id );

				$create_result = ClientRepository::create(
					array(
						'client_id'                  => $new_client_id,
						'server_id'                  => $server_id,
						'client_secret'              => null, // Admin-issued clients don't do client-auth.
						'client_name'                => 'n8n',
						'redirect_uris'              => array(), // Server-side minting — no browser round-trip; DCR redirect_uri validation is a no-op.
						'grant_types'                => '',      // No grant flow — token minted directly.
						'token_endpoint_auth_method' => 'none',
						'connector_slug'             => self::CONNECTOR_SLUG,
						'metadata_fingerprint'       => '',      // A4 — empty fingerprint classifies as admin-issued (not CIMD/verified).
					)
				);
				if ( ! is_int( $create_result ) || $create_result <= 0 ) {
					return new \WP_Error(
						'rest_n8n_client_create_failed',
						__( 'Could not create the n8n admin client.', 'acrossai-mcp-manager' ),
						array( 'status' => 500 )
					);
				}

				$client = ClientRepository::find_admin_client( $server_id, self::CONNECTOR_SLUG );
				if ( null === $client ) {
					return new \WP_Error(
						'rest_n8n_client_lookup_failed',
						__( 'Could not resolve the n8n admin client after creation.', 'acrossai-mcp-manager' ),
						array( 'status' => 500 )
					);
				}
			}

			$user_id = (int) get_current_user_id();

			// Revoke ONLY this admin's prior tokens on the shared client
			// (C3 cross-admin isolation). Fire the existing revoke action per
			// row with the new reason enum value `n8n_admin_regenerated`.
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id_and_user_id(
				(string) $client->client_id,
				$server_id,
				$user_id
			);
			foreach ( $revoked_ids as $revoked_id ) {
				do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $revoked_id, 'n8n_admin_regenerated' );
			}
			$regenerated = ! empty( $revoked_ids );

			// Mint the new token with the operator-selected TTL. Repository
			// layer clamps to MAX_ADMIN_TTL_SECONDS as defense-in-depth (C2).
			$token = AccessTokenRepository::issue(
				array(
					'client_id'       => (string) $client->client_id,
					'server_id'       => $server_id,
					'user_id'         => $user_id,
					'scope'           => 'mcp',
					'resource'        => $mcp_url,
					'token_family_id' => wp_generate_uuid4(),
					'ttl_seconds'     => $ttl_seconds,
				)
			);

			// Extended audit fire (C4). See file docblock for the extended
			// signature contract. Wrapped in try/catch so subscriber
			// exceptions cannot poison this REST response (invariant 6).
			try {
				do_action(
					'acrossai_mcp_manager_oauth_token_issued',
					(int) $token['id'],
					(string) $client->client_id,
					$user_id,
					self::CONNECTOR_SLUG,
					array(
						'flow'        => 'n8n_admin',
						'server_id'   => $server_id,
						'ttl_seconds' => $ttl_seconds,
						'expires_at'  => strtotime( (string) $token['expires_at'] . ' UTC' ),
						'regenerated' => $regenerated,
					)
				);
			} catch ( \Throwable $e ) {
				error_log( '[acrossai-mcp-manager] n8n_token_issued subscriber threw: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return CacheHeaders::apply_to_rest_response(
				new \WP_REST_Response(
					array(
						'access_token' => $token['raw'],
						'expires_at'   => strtotime( (string) $token['expires_at'] . ' UTC' ),
						'resource'     => $mcp_url,
						'regenerated'  => $regenerated,
					),
					200
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e ); // Do NOT leak exception details into the wire body
			// (mirror of SEC-021-T06 in ClientRegistrationController).
			return new \WP_Error(
				'rest_n8n_token_mint_failed',
				__( 'Could not mint the n8n admin token.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Structured admin client_id: `server-{server_id}-n8n-{rand8}`. Mirror
	 * of ClientRegistrationController::admin_client_id().
	 *
	 * @param  int $server_id Owning MCP server.
	 * @return string
	 */
	private static function admin_client_id( int $server_id ): string {
		return 'server-' . $server_id . '-' . self::CONNECTOR_SLUG . '-' . SecretsVault::random_hex( 4 );
	}
}
