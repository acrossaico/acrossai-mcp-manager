<?php
/**
 * Bearer-token authenticator on `determine_current_user @ 20` (Feature 021).
 *
 * FR-024/FR-025/FR-026 + Q1 audience-binding + SC-011 short-circuit.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSlugDisplay;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Row as TokenRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class TokenValidator {

	/** @var TokenValidator|null */
	private static $instance = null;

	/**
	 * FR-025 static recursion guard. A downstream `current_user_can` call
	 * mid-lookup MUST NOT re-trigger the filter.
	 *
	 * @var bool
	 */
	private static $resolving = false;

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
	 * `determine_current_user @ 20` callback.
	 *
	 * On any failure path returns `$user_id` unchanged (never partial auth,
	 * never fake-authenticate). Short-circuits before any DB call when no
	 * bearer header is present (SC-011).
	 *
	 * @param int|false|null $user_id Value from prior filters.
	 * @return int|false|null
	 */
	public function authenticate( $user_id ) {
		if ( self::$resolving ) {
			return $user_id;
		}

		if ( is_int( $user_id ) && $user_id > 0 ) {
			// Already authenticated (cookies, application password). Skip.
			return $user_id;
		}

		$raw_token = self::read_bearer_token();
		if ( null === $raw_token ) {
			return $user_id;
		}

		self::$resolving = true;
		try {
			$row = AccessTokenRepository::find_by_hash( SecretsVault::hash( $raw_token ) );
			if ( null === $row ) {
				return $user_id;
			}

			if ( 'access' !== $row->token_type ) {
				// Refresh tokens are NOT bearer credentials.
				return $user_id;
			}

			if ( ! $row->is_active( gmdate( 'Y-m-d H:i:s' ) ) ) {
				return $user_id;
			}

			if ( ! self::audience_matches_request( $row ) ) {
				// Q1 audience-binding — cross-server invocation = anonymous.
				return $user_id;
			}

			if ( ! self::connector_enabled_for_token( $row ) ) {
				// FR-006 / mcp-manager FR-024-017 — defensive layer. Disabling a
				// connector already mass-revokes its tokens in the same request
				// (ConnectorAdminController::handle_save_server_settings), so a
				// live token for a disabled connector should not exist. This
				// catches the races that revocation cannot: a token minted
				// between the settings read and the revoke sweep, a request
				// already in flight when the sweep ran, or a row the sweep
				// missed because its client was attributed to the connector
				// after the fact. Specified in 024-connectors-nested-tabs and
				// never implemented until Feature 024.
				return $user_id;
			}

			$resolved = (int) $row->user_id;
			return $resolved > 0 ? $resolved : $user_id;
		} finally {
			self::$resolving = false;
		}
	}

	/**
	 * Read the raw bearer token from the request. Tries 4 header source
	 * fallbacks so proxied SAPI setups don't lose the header.
	 *
	 * @return string|null
	 */
	private static function read_bearer_token(): ?string {
		$candidates = array();

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$candidates[] = (string) $_SERVER['HTTP_AUTHORIZATION'];
		}
		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$candidates[] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( function_exists( 'apache_request_headers' ) ) {
			$apache = apache_request_headers();
			if ( is_array( $apache ) && isset( $apache['Authorization'] ) ) {
				$candidates[] = (string) $apache['Authorization'];
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			$all = getallheaders();
			if ( is_array( $all ) && isset( $all['Authorization'] ) ) {
				$candidates[] = (string) $all['Authorization'];
			}
		}

		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			// Case-insensitive "Bearer " prefix.
			if ( 0 === stripos( $candidate, 'Bearer ' ) ) {
				$token = trim( substr( $candidate, 7 ) );
				if ( '' !== $token ) {
					return $token;
				}
			}
		}

		return null;
	}

	/**
	 * Q1 RFC 8707 audience-binding check.
	 *
	 * Token is only valid against a specific MCP endpoint URL. Callers that
	 * present a token for one server against a different server URL on the
	 * same site must be rejected (returns false → callback returns
	 * `$user_id` unchanged → mcp-adapter denies at `current_user_can`).
	 *
	 * @param TokenRow $row Token row.
	 * @return bool
	 */
	private static function audience_matches_request( TokenRow $row ): bool {
		if ( '' === $row->resource ) {
			// Legacy row from before audience-binding was enforced — reject.
			return false;
		}

		$request_url = self::current_request_url();
		if ( '' === $request_url ) {
			return false;
		}

		return self::url_matches_resource( $request_url, (string) $row->resource );
	}

	/**
	 * Whether the connector this token belongs to is still enabled on the
	 * server the token was issued for (FR-006, mcp-manager FR-024-017).
	 *
	 * Resolves the connector through the token's client row, because
	 * `connector_slug` lives on the client, not the token. Three fail-open
	 * cases, each deliberate:
	 *
	 *  - `server_id <= 0` — a pre-F032 row with no server binding. There is no
	 *    per-server setting to consult, so this check abstains; the audience
	 *    check above has already constrained which endpoint the token works on.
	 *  - client row missing — cannot attribute the token to any connector, so
	 *    there is nothing to disable. Not a silent pass for a disabled
	 *    connector; it is a pass for an unattributable one.
	 *  - empty `connector_slug` — a DCR client no profile claimed. These ride on
	 *    the server's `allow_other` toggle, which `is_slug_enabled_on_server()`
	 *    resolves via ConnectorSlugDisplay::bucket(), so it IS checked.
	 *
	 * @param TokenRow $row Token row.
	 * @return bool
	 */
	private static function connector_enabled_for_token( TokenRow $row ): bool {
		$server_id = (int) $row->server_id;
		if ( $server_id <= 0 ) {
			return true;
		}

		$client = ClientRepository::find_by_id( (string) $row->client_id );
		if ( null === $client ) {
			return true;
		}

		$slug = ConnectorSlugDisplay::bucket( (string) $client->connector_slug );

		return ConnectorSettings::is_slug_enabled_on_server( $server_id, $slug );
	}

	/**
	 * Reconstruct the target request URL as-seen by the client.
	 *
	 * @return string
	 */
	private static function current_request_url(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$request_uri = (string) $_SERVER['REQUEST_URI'];

		// Build against the site URL. home_url() honours the site's canonical scheme.
		return home_url( $request_uri );
	}

	/**
	 * True iff the request URL falls within the token's resource scope.
	 *
	 * "Within scope" = the request URL's path starts with the resource's
	 * path (including exact match). Query strings are ignored; the same
	 * MCP endpoint may be called with different query args.
	 *
	 * @param string $request_url  Current request URL.
	 * @param string $resource_url Token's stored resource URL.
	 * @return bool
	 */
	private static function url_matches_resource( string $request_url, string $resource_url ): bool {
		$request_parts  = wp_parse_url( $request_url );
		$resource_parts = wp_parse_url( $resource_url );

		if ( ! is_array( $request_parts ) || ! is_array( $resource_parts ) ) {
			return false;
		}

		// Scheme + host must byte-match (case-insensitive on host per RFC 3986).
		$request_host  = isset( $request_parts['host'] ) ? strtolower( (string) $request_parts['host'] ) : '';
		$resource_host = isset( $resource_parts['host'] ) ? strtolower( (string) $resource_parts['host'] ) : '';
		if ( ! hash_equals( $resource_host, $request_host ) ) {
			return false;
		}

		$request_scheme  = isset( $request_parts['scheme'] ) ? strtolower( (string) $request_parts['scheme'] ) : '';
		$resource_scheme = isset( $resource_parts['scheme'] ) ? strtolower( (string) $resource_parts['scheme'] ) : '';
		if ( ! hash_equals( $resource_scheme, $request_scheme ) ) {
			return false;
		}

		// Port must match — default to scheme-default when omitted.
		$request_port  = isset( $request_parts['port'] ) ? (int) $request_parts['port'] : self::default_port( $request_scheme );
		$resource_port = isset( $resource_parts['port'] ) ? (int) $resource_parts['port'] : self::default_port( $resource_scheme );
		if ( $request_port !== $resource_port ) {
			return false;
		}

		// Path must be a prefix of the request path.
		$resource_path = isset( $resource_parts['path'] ) ? rtrim( (string) $resource_parts['path'], '/' ) : '';
		$request_path  = isset( $request_parts['path'] ) ? (string) $request_parts['path'] : '';

		if ( '' === $resource_path ) {
			// Empty resource path is not permitted — the whole site can't be a valid audience.
			return false;
		}

		return $request_path === $resource_path || 0 === strpos( $request_path, $resource_path . '/' );
	}

	/**
	 * Default port for a URL scheme.
	 *
	 * @param string $scheme
	 * @return int
	 */
	private static function default_port( string $scheme ): int {
		if ( 'https' === $scheme ) {
			return 443;
		}
		if ( 'http' === $scheme ) {
			return 80;
		}
		return 0;
	}
}
