<?php
/**
 * /token endpoint — authorization_code + refresh_token grants.
 *
 * FR-013..FR-019 + SEC-021-001 family revocation (RFC 9700 §2.2.2) +
 * atomic single-use auth codes (B10) + refresh rotation.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSlugDisplay;
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AuthCodeRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\RefreshTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\RateLimiter;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class TokenController {

	/** @var TokenController|null */
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
	 * FR-013 dispatcher.
	 *
	 * @return void
	 */
	public function handle(): void {
		self::apply_rate_limit();

		$body = self::read_body();

		$grant_type = isset( $body['grant_type'] ) ? (string) $body['grant_type'] : '';

		switch ( $grant_type ) {
			case 'authorization_code':
				$this->handle_authorization_code( $body );
				break;
			case 'refresh_token':
				$this->handle_refresh_token( $body );
				break;
			case '':
				self::respond_error( 'invalid_request', 'Missing grant_type', 400 );
				break;
			default:
				self::respond_error( 'unsupported_grant_type', 'Unsupported grant_type', 400 );
		}
	}

	/**
	 * FR-014..FR-016 authorization_code grant.
	 *
	 * @param array<string, string> $body Parsed request body.
	 * @return void
	 */
	private function handle_authorization_code( array $body ): void {
		// Resolve client credentials from Authorization: Basic header first
		// (RFC 6749 §2.3.1 RECOMMENDED), falling back to body params.
		[ $header_client_id, $header_client_secret ] = self::read_client_credentials_from_header();
		$body_client_id                              = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';
		$body_client_secret                          = isset( $body['client_secret'] ) ? (string) $body['client_secret'] : '';
		$client_id                                   = '' !== $header_client_id ? $header_client_id : $body_client_id;
		$client_secret                               = '' !== $header_client_secret ? $header_client_secret : $body_client_secret;

		$required = array( 'code', 'code_verifier', 'redirect_uri' );
		foreach ( $required as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				self::respond_error( 'invalid_request', 'Missing required field: ' . $field, 400 );
			}
		}
		if ( '' === $client_id ) {
			self::respond_error( 'invalid_request', 'Missing required field: client_id', 400 );
		}

		$row = AuthCodeRepository::consume_atomic( $body['code'] );
		if ( null === $row ) {
			self::respond_error( 'invalid_grant', 'Auth code is invalid or has already been used.', 400 );
		}

		if ( ! hash_equals( (string) $row->client_id, $client_id ) ) {
			self::respond_error( 'invalid_grant', 'client_id mismatch', 400 );
		}
		if ( ! hash_equals( (string) $row->redirect_uri, (string) $body['redirect_uri'] ) ) {
			self::respond_error( 'invalid_grant', 'redirect_uri mismatch', 400 );
		}

		$client = ClientRepository::find_by_id( $client_id );
		if ( null === $client ) {
			self::respond_error( 'invalid_client', 'Client not registered', 401 );
		}

		// client_secret_post clients: verify the submitted secret when one
		// is present. When NO secret was submitted (via header or body),
		// fall through to PKCE-only verification — modern MCP hosts
		// (Claude.ai, ChatGPT) register as client_secret_post but behave
		// as public+PKCE at token exchange, never carrying the secret.
		// Rejecting them for a missing secret would break interop; PKCE
		// still authenticates the exchange.
		if ( 'client_secret_post' === $client->token_endpoint_auth_method && '' !== $client_secret ) {
			if ( ! ClientRepository::verify_secret( $client, $client_secret ) ) {
				self::respond_error( 'invalid_client', 'client_secret verification failed', 401 );
			}
		}

		if ( ! PKCE::verify_s256( (string) $body['code_verifier'], (string) $row->code_challenge ) ) {
			self::respond_error( 'invalid_grant', 'PKCE verification failed', 400 );
		}

		// SEC-021-001 — mint a fresh family_id for this auth-code chain.
		$family_id = wp_generate_uuid4();
		$user_id   = (int) $row->user_id;
		$resource  = (string) $row->resource;
		$scope     = '' !== $row->scope ? (string) $row->scope : 'mcp';

		// F032 (T039) — copy server_id from the consumed auth_code row onto the
		// emitted token. Post-migration invariant: server_id is NEVER 0 on any
		// auth_code row (SQL NOT NULL) so a 0 here would indicate data corruption.
		$server_id = (int) $row->server_id;

		// F032 (T039) — defense-in-depth: verify the client we're minting for is
		// bound to the same server as the auth_code. Should be structurally
		// impossible after F032 (auth_code carries server_id resolved at authorize
		// time against the same resource that the client belongs to) but guards
		// any data-corruption case where the two diverged.
		//
		// A divergence is legitimate when the auth_code was issued for an RFC 8707
		// `resource` that resolves to a different (but real) server than the one
		// the client registered against — one MCP host may connect to several
		// servers on this site. Reject only when the code's server_id does not
		// match its own resource, which is true corruption.
		if ( $server_id > 0 && (int) $client->server_id !== $server_id
			&& AuthorizationController::server_id_from_resource( $resource ) !== $server_id ) {
			self::respond_error( 'invalid_grant', 'server_id mismatch between client and auth_code', 400 );
		}

		$access  = AccessTokenRepository::issue(
			array(
				'client_id'       => $client_id,
				// F032 (T039) — carry server binding through to token row.
				'server_id'       => $server_id,
				'user_id'         => $user_id,
				'scope'           => $scope,
				'resource'        => $resource,
				'token_family_id' => $family_id,
			)
		);
		$refresh = RefreshTokenRepository::issue(
			array(
				'client_id'       => $client_id,
				'server_id'       => $server_id,
				'user_id'         => $user_id,
				'scope'           => $scope,
				'resource'        => $resource,
				'token_family_id' => $family_id,
			)
		);

		/**
		 * Action: acrossai_mcp_manager_oauth_token_issued
		 */
		do_action( 'acrossai_mcp_manager_oauth_token_issued', (int) $access['id'], $client_id, $user_id, (string) $client->connector_slug );

		self::respond_token_success(
			array(
				'access_token'  => (string) $access['raw'],
				'token_type'    => 'Bearer',
				'expires_in'    => AccessTokenRepository::ttl(),
				'refresh_token' => (string) $refresh['raw'],
				'scope'         => $scope,
				'resource'      => $resource,
			)
		);
	}

	/**
	 * FR-017 refresh_token grant with SEC-021-001 family revocation.
	 *
	 * @param array<string, string> $body Parsed request body.
	 * @return void
	 */
	private function handle_refresh_token( array $body ): void {
		// Resolve client credentials from Authorization: Basic header first
		// (RFC 6749 §2.3.1 RECOMMENDED), falling back to body params.
		[ $header_client_id, $header_client_secret ] = self::read_client_credentials_from_header();
		$body_client_id                              = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';
		$body_client_secret                          = isset( $body['client_secret'] ) ? (string) $body['client_secret'] : '';
		$client_id                                   = '' !== $header_client_id ? $header_client_id : $body_client_id;
		$client_secret                               = '' !== $header_client_secret ? $header_client_secret : $body_client_secret;

		if ( ! isset( $body['refresh_token'] ) || '' === $body['refresh_token'] || '' === $client_id ) {
			self::respond_error( 'invalid_request', 'Missing refresh_token or client_id', 400 );
		}

		$row = RefreshTokenRepository::find_by_hash( SecretsVault::hash( (string) $body['refresh_token'] ) );
		if ( null === $row || 'refresh' !== $row->token_type ) {
			self::respond_error( 'invalid_grant', 'Unknown refresh token', 400 );
		}

		// SEC-021-001 — reuse detection. A refresh token whose row is already
		// revoked implies compromise: bulk-revoke the entire family so the
		// attacker's rotated pair also dies.
		if ( 1 === (int) $row->revoked ) {
			$family_id = (string) $row->token_family_id;
			if ( '' !== $family_id ) {
				$revoked_ids = RefreshTokenRepository::revoke_by_family_id( $family_id );
				foreach ( $revoked_ids as $token_id ) {
					do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'family_reuse_detected' );
				}
			}
			self::respond_error( 'invalid_grant', 'Refresh token has been revoked.', 400 );
		}

		if ( ! hash_equals( (string) $row->client_id, $client_id ) ) {
			self::respond_error( 'invalid_grant', 'client_id mismatch', 400 );
		}

		$now_ts = time();
		if ( strtotime( (string) $row->expires_at . ' GMT' ) < $now_ts ) {
			self::respond_error( 'invalid_grant', 'Refresh token expired', 400 );
		}

		$client = ClientRepository::find_by_id( $client_id );
		if ( null === $client ) {
			self::respond_error( 'invalid_client', 'Client not registered', 401 );
		}

		// client_secret_post clients: verify the submitted secret when one
		// is present. When NO secret was submitted, fall through — the
		// refresh token itself (bound to the client via row->client_id)
		// authenticates the exchange for public+PKCE-style clients.
		if ( 'client_secret_post' === $client->token_endpoint_auth_method && '' !== $client_secret ) {
			if ( ! ClientRepository::verify_secret( $client, $client_secret ) ) {
				self::respond_error( 'invalid_client', 'client_secret verification failed', 401 );
			}
		}

		// FR-007 — a refresh is a re-authorization, so it must answer to the
		// current setting rather than the one that held when the grant was
		// first issued. Without this a connector disabled mid-session keeps
		// renewing itself indefinitely: the mass-revoke at disable time kills
		// the tokens that exist, but a client holding a refresh token issued
		// microseconds earlier simply mints a new pair and carries on. The
		// /authorize gate never sees it again — refresh does not pass through
		// /authorize.
		//
		// `server_id <= 0` abstains for the same reason as TokenValidator:
		// a pre-F032 row has no server binding and therefore no per-server
		// setting to consult.
		$refresh_server_id = (int) $row->server_id;
		if ( $refresh_server_id > 0 ) {
			$refresh_slug = ConnectorSlugDisplay::bucket( (string) $client->connector_slug );
			if ( ! ConnectorSettings::is_slug_enabled_on_server( $refresh_server_id, $refresh_slug ) ) {
				self::respond_error( 'invalid_grant', 'This connector is not enabled on this server.', 400 );
			}
		}

		// Rotate — revoke the presented refresh, issue a fresh pair carrying
		// resource/scope/family_id forward.
		RefreshTokenRepository::revoke_by_hash( SecretsVault::hash( (string) $body['refresh_token'] ) );
		do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $row->id, 'refresh_rotation' );

		$family_id = (string) $row->token_family_id;
		if ( '' === $family_id ) {
			// Legacy row from before SEC-021-001 — mint a fresh family_id.
			$family_id = wp_generate_uuid4();
		}

		$user_id  = (int) $row->user_id;
		$resource = (string) $row->resource;
		$scope    = '' !== $row->scope ? (string) $row->scope : 'mcp';

		// F032 (T040) — copy server_id from the prior refresh token row so the
		// refreshed access + refresh pair inherit the same per-server binding.
		// Post-migration invariant: prior token row's server_id is NEVER 0.
		$server_id = (int) $row->server_id;

		$access      = AccessTokenRepository::issue(
			array(
				'client_id'       => $client_id,
				'server_id'       => $server_id,
				'user_id'         => $user_id,
				'scope'           => $scope,
				'resource'        => $resource,
				'token_family_id' => $family_id,
			)
		);
		$new_refresh = RefreshTokenRepository::issue(
			array(
				'client_id'       => $client_id,
				'server_id'       => $server_id,
				'user_id'         => $user_id,
				'scope'           => $scope,
				'resource'        => $resource,
				'token_family_id' => $family_id,
			)
		);

		do_action( 'acrossai_mcp_manager_oauth_token_issued', (int) $access['id'], $client_id, $user_id, (string) $client->connector_slug );

		self::respond_token_success(
			array(
				'access_token'  => (string) $access['raw'],
				'token_type'    => 'Bearer',
				'expires_in'    => AccessTokenRepository::ttl(),
				'refresh_token' => (string) $new_refresh['raw'],
				'scope'         => $scope,
				'resource'      => $resource,
			)
		);
	}

	/**
	 * RFC 6749 §2.3.1 — parse client credentials from Authorization: Basic
	 * header. Handles both HTTP_AUTHORIZATION and the CGI fallback
	 * REDIRECT_HTTP_AUTHORIZATION. Returns [$client_id, $client_secret];
	 * empty strings when the header is absent or malformed.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function read_client_credentials_from_header(): array {
		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if ( 0 !== stripos( $auth_header, 'Basic ' ) ) {
			return array( '', '' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 6749 §2.3.1 Basic auth requires base64 decoding of the header value.
		$decoded = base64_decode( substr( $auth_header, 6 ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return array( '', '' );
		}

		[ $cred_id, $cred_secret ] = explode( ':', $decoded, 2 );
		return array( (string) $cred_id, (string) $cred_secret );
	}

	/**
	 * Parse the request body — accepts application/json or form-urlencoded.
	 *
	 * @return array<string, string>
	 */
	private static function read_body(): array {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( (string) $_SERVER['CONTENT_TYPE'] ) : '';

		if ( 0 === strpos( $content_type, 'application/json' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Streaming request body from php://input.
			$raw = file_get_contents( 'php://input' );
			if ( false === $raw ) {
				$raw = '';
			}
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Token endpoint uses body-authenticated flow (RFC 6749 §2.3.1).
		return array_map( 'strval', wp_unslash( $_POST ) );
	}

	/**
	 * Send an RFC 6749 shaped success response.
	 *
	 * @param array<string, mixed> $payload
	 * @return void
	 */
	private static function respond_token_success( array $payload ): void {
		status_header( 200 );
		CacheHeaders::send_no_store();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $payload );
		exit;
	}

	/**
	 * Send an RFC 6749 shaped error response.
	 *
	 * @param string $error
	 * @param string $description
	 * @param int    $status
	 * @return void
	 */
	private static function respond_error( string $error, string $description, int $status ): void {
		status_header( $status );
		CacheHeaders::send_no_store();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'error'             => $error,
				'error_description' => $description,
			)
		);
		exit;
	}

	/**
	 * FR-028 — 60/IP/60s for /token, shared bucket key with /authorize.
	 *
	 * @return void
	 */
	private static function apply_rate_limit(): void {
		$check = RateLimiter::check( 'token', RateLimiter::client_ip(), 60, 60 );
		if ( $check instanceof \WP_Error ) {
			status_header( 429 );
			CacheHeaders::send_no_store();
			header( 'Retry-After: 60' );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode(
				array(
					'error'             => 'slow_down',
					'error_description' => 'Rate limit exceeded; retry in 60 seconds.',
				)
			);
			exit;
		}
	}
}
