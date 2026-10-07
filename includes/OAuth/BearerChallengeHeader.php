<?php
/**
 * Adds RFC 6750 / RFC 9728 `WWW-Authenticate` header to 401 responses
 * from MCP REST routes so AI clients can auto-discover this site's OAuth
 * authorization server.
 *
 * Without this header, an AI client hitting a protected MCP endpoint sees
 * WordPress's default 401 body but has no pointer to the RFC 9728 protected
 * resource metadata. It cannot follow the discovery chain to `/oauth/register`
 * and DCR silently fails. Anthropic's Claude connector proxy returns
 * "Authorization with the MCP server failed" in that state.
 *
 * Hook: `rest_post_dispatch` at priority 10. Runs AFTER the mcp-adapter's
 * permission callback returned WP_Error, but BEFORE headers are flushed.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 * @since 0.1.0 (F024 hotfix — 2026-07-11)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class BearerChallengeHeader {

	/** @var BearerChallengeHeader|null */
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
	 * Add `WWW-Authenticate: Bearer resource_metadata="..."` to 401
	 * responses from any MCP REST route. Only mutates responses that
	 * genuinely correspond to protected MCP resources — other REST 401s
	 * (e.g. WP admin auth) are left alone.
	 *
	 * @param \WP_HTTP_Response|\WP_REST_Response|mixed $response Response object.
	 * @param \WP_REST_Server                           $server   REST server instance.
	 * @param \WP_REST_Request                          $request  Inbound request.
	 * @return mixed The response object (mutated in place when applicable).
	 */
	public function add_bearer_challenge( $response, $server, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $server required by hook signature.
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_status' ) ) {
			return $response;
		}
		if ( 401 !== (int) $response->get_status() ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! is_string( $route ) || '' === $route ) {
			return $response;
		}

		// Only challenge on routes that actually belong to an MCP server. The
		// previous `strpos( $route, '/mcp' ) === 0` prefix test both over-matched
		// (any namespace merely STARTING with "mcp") and under-matched: a
		// database-registered server may use any `server_route_namespace`, and
		// those servers got no challenge header at all, so a client had nothing
		// to discover and the connector silently failed to authenticate.
		$resource_url = rest_url( ltrim( $route, '/' ) );
		if ( AuthorizationController::server_id_from_resource( $resource_url ) <= 0
			&& 0 !== strpos( $route, '/mcp/' ) ) {
			return $response;
		}

		// RFC 9728 §3.1 — the metadata URL inserts the well-known prefix between
		// the resource's host and its path. Emit that (not the legacy
		// `?resource=` form) so hosts which construct the URL themselves and
		// hosts which follow this header both land on the same document.
		$resource_path = (string) wp_parse_url( $resource_url, PHP_URL_PATH );
		$metadata_url  = home_url( '/.well-known/oauth-protected-resource/' . ltrim( $resource_path, '/' ) );

		$header_value = sprintf(
			'Bearer resource_metadata="%s"',
			esc_url_raw( $metadata_url )
		);

		if ( method_exists( $response, 'header' ) ) {
			$response->header( 'WWW-Authenticate', $header_value );
		}

		return $response;
	}
}
