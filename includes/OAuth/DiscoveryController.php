<?php
/**
 * RFC 8414 authorization server metadata + RFC 9728 protected resource
 * metadata (Feature 021).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;

defined( 'ABSPATH' ) || exit;

final class DiscoveryController {

	/** @var DiscoveryController|null */
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
	 * FR-001 — GET `/.well-known/oauth-authorization-server`.
	 *
	 * @return void
	 */
	public function render_authorization_server_metadata(): void {
		self::send_metadata_headers();

		wp_send_json( self::authorization_server_document() );
	}

	/**
	 * The authorization-server metadata, as data.
	 *
	 * Split out from the renderer so it can be produced without emitting a
	 * response. The troubleshooter builds the document in-process and compares
	 * it with what an HTTP fetch of the same URL returns: when they differ, a
	 * cache is serving something this site no longer says. That comparison is
	 * only possible if the document exists independently of `wp_send_json()`,
	 * which exits.
	 *
	 * @return array<string, mixed>
	 */
	public static function authorization_server_document(): array {
		$issuer = self::issuer();

		return array(
			'issuer'                                => $issuer,
			'authorization_endpoint'                => $issuer . '/authorize',
			'token_endpoint'                        => $issuer . '/token',
			'registration_endpoint'                 => rest_url( 'acrossai-mcp-manager/v1/oauth/register' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'response_types_supported'              => array( 'code' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'scopes_supported'                      => array( 'mcp' ),
			'authorization_response_iss_parameter_supported' => true,
			'service_documentation'                 => admin_url( 'admin.php?page=acrossai_mcp_manager' ),
		);
	}

	/**
	 * FR-002 — GET `/.well-known/oauth-protected-resource`, in both the bare
	 * form and the RFC 9728 §3.1 path-inserted form.
	 *
	 * @param string $path_suffix Path captured from the path-inserted rewrite
	 *                            rule (e.g. `wp-json/mcp/testing-servers`);
	 *                            empty for the bare document.
	 * @return void
	 */
	public function render_protected_resource_metadata( string $path_suffix = '' ): void {
		$requested_resource = '';

		// Preferred, spec-compliant form (RFC 9728 §3.1): the resource's path is
		// inserted after the well-known prefix. Reconstruct the absolute URL from
		// the site origin + that path — the inverse of the transformation the
		// client applied.
		if ( '' !== $path_suffix ) {
			$requested_resource = self::resource_url_from_path( $path_suffix );
		}

		// Legacy form: `?resource=` query arg. Emitted by BearerChallengeHeader
		// for backwards compatibility with clients already paired against this
		// site; kept so existing connectors do not break on upgrade.
		if ( '' === $requested_resource ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public discovery endpoint, no state mutation.
			$requested_resource = isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( (string) $_GET['resource'] ) ) : '';
		}

		// Only advertise a resource this site actually serves. Echoing an
		// arbitrary caller-supplied URL would make the document a reflection
		// primitive and would tell a client its (wrong) URL is valid.
		if ( '' !== $requested_resource && ! self::is_known_resource( $requested_resource ) ) {
			// Deliberately NOT cached: an operator who enables the server a
			// minute later must not be shadowed by an hour-old negative answer
			// sitting in a CDN or client cache.
			status_header( 404 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			// Same reason as the success path: a page cache never reads the
			// header above. Without this, a negative answer produced during a
			// transient bad state outlives the fix.
			CacheHeaders::define_donotcache_constant();
			header( 'Access-Control-Allow-Origin: *' );
			wp_send_json(
				array(
					'error'             => 'invalid_target',
					'error_description' => 'Unknown protected resource on this site.',
				),
				404
			);
		}

		self::send_metadata_headers();

		wp_send_json( self::protected_resource_document( $requested_resource ) );
	}

	/**
	 * The protected-resource metadata, as data. Same reasoning as
	 * {@see self::authorization_server_document()}.
	 *
	 * @param string $requested_resource Absolute resource URL, already
	 *                                   validated by the caller. Empty uses
	 *                                   this site's default server.
	 * @return array<string, mixed>
	 */
	public static function protected_resource_document( string $requested_resource = '' ): array {
		return array(
			'resource'                 => '' !== $requested_resource ? $requested_resource : self::default_resource_url(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'mcp' ),
		);
	}

	/**
	 * Rebuild an absolute resource URL from an RFC 9728 §3.1 path suffix.
	 *
	 * The suffix is the *path* component of the resource identifier, so it is
	 * joined to the site's scheme://host[:port] — NOT to home_url(), which may
	 * already carry a subdirectory that the suffix also contains.
	 *
	 * @param string $path_suffix Path captured from the rewrite rule, no leading slash.
	 * @return string Absolute URL, or '' when the suffix is unusable.
	 */
	private static function resource_url_from_path( string $path_suffix ): string {
		$path_suffix = trim( $path_suffix, '/' );
		if ( '' === $path_suffix || false !== strpos( $path_suffix, '..' ) ) {
			return '';
		}

		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin . '/' . $path_suffix;
	}

	/**
	 * Does this URL identify an MCP server route registered on this site?
	 *
	 * Degrades open when mcp-manager's server table is unavailable (base plugin
	 * missing, or pre-Feature-021 schema) so discovery keeps working instead of
	 * 404ing every request.
	 *
	 * @param string $resource_url Candidate resource identifier.
	 * @return bool
	 */
	private static function is_known_resource( string $resource_url ): bool {
		if ( ! class_exists( '\\AcrossAI_MCP_Manager\\Includes\\Database\\MCPServer\\Query' ) ) {
			return true;
		}

		// `server_id_from_resource()` matches on path alone, so pin the origin
		// here — otherwise `https://evil.example/wp-json/mcp/<route>` would be
		// vouched for by this site's metadata document.
		$candidate = wp_parse_url( $resource_url );
		$site      = wp_parse_url( home_url() );
		if ( ! is_array( $candidate ) || ! is_array( $site ) ) {
			return false;
		}
		if ( ( $candidate['host'] ?? '' ) !== ( $site['host'] ?? '' )
			|| ( $candidate['scheme'] ?? '' ) !== ( $site['scheme'] ?? '' )
			|| ( $candidate['port'] ?? null ) !== ( $site['port'] ?? null ) ) {
			return false;
		}

		return AuthorizationController::server_id_from_resource( $resource_url ) > 0;
	}

	/**
	 * The MCP server row that this site presents as its default when
	 * discovery is queried without an explicit `?resource=`. Shared with
	 * ClientRegistrationController so DCR auto-binds to the same server
	 * the well-known metadata advertises — otherwise Claude reads one URL
	 * from discovery, sends DCR with no `resource`, and DCR picks (or
	 * rejects for ambiguity) a different server.
	 *
	 * Returns the first `is_enabled=1` row, or null when mcp-manager is
	 * absent / no enabled server exists.
	 *
	 * @return object|null
	 */
	public static function default_server_row() {
		if ( ! class_exists( '\\AcrossAI_MCP_Manager\\Includes\\Database\\MCPServer\\Query' ) ) {
			return null;
		}

		$servers = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
			array(
				'number'     => 1,
				'is_enabled' => 1,
			)
		);

		return empty( $servers ) ? null : $servers[0];
	}

	/**
	 * Fallback resource URL used when no enabled server row is available.
	 * Does not resolve on most installs; callers should register at least
	 * one server before relying on this.
	 *
	 * @return string
	 */
	private static function fallback_resource_url(): string {
		return rest_url( 'mcp/v1' );
	}

	/**
	 * Default `resource` URL for the well-known metadata response when no
	 * explicit `?resource=` query parameter is supplied.
	 *
	 * @return string
	 */
	private static function default_resource_url(): string {
		$server_row = self::default_server_row();
		if ( null === $server_row ) {
			return self::fallback_resource_url();
		}

		$route = (string) $server_row->server_route;
		if ( '' === $route ) {
			return self::fallback_resource_url();
		}

		$namespace = '' !== $server_row->server_route_namespace
			? (string) $server_row->server_route_namespace
			: 'mcp';

		return rest_url( trailingslashit( $namespace ) . $route );
	}

	/**
	 * Default seconds a discovery document may be cached.
	 *
	 * Was 3600. An hour of stale metadata costs far more in support than it
	 * saves in bandwidth: a document captured while the site was in a
	 * transient bad state — mid-upgrade, before the rewrite flush, server not
	 * yet enabled, discovery temporarily owned by another plugin — was served
	 * for an hour AFTER the operator fixed the real problem, so their re-test
	 * failed and nothing told them a cache was involved. Sixty seconds still
	 * absorbs a client's burst of discovery requests during one connection
	 * attempt, which is all the caching was ever buying.
	 *
	 * @var int
	 */
	private const METADATA_MAX_AGE = 60;

	/**
	 * Standard cache + CORS headers for both metadata endpoints.
	 */
	private static function send_metadata_headers(): void {
		/**
		 * Filters how long a discovery document may be cached, in seconds.
		 *
		 * Zero sends `no-store` instead, for a site that would rather pay for
		 * the requests than ever debug a stale document.
		 *
		 * @since 0.4.6
		 *
		 * @param int $max_age Seconds. Default 60.
		 */
		$max_age = (int) apply_filters( 'acrossai_mcp_discovery_cache_max_age', self::METADATA_MAX_AGE );

		header( 'Content-Type: application/json; charset=utf-8' );

		if ( $max_age > 0 ) {
			header( sprintf( 'Cache-Control: public, max-age=%d', $max_age ) );
		} else {
			header( 'Cache-Control: no-store' );
		}

		// Headers tell well-behaved HTTP caches what to do. A WordPress page
		// cache is not an HTTP cache: it stores the response before anything
		// reads Cache-Control, which is how a customer site ended up serving
		// an hour-old discovery document that no amount of header-correctness
		// would have prevented. DONOTCACHEPAGE is the signal nearly every
		// caching plugin honours, and it is the only one that reaches them.
		CacheHeaders::define_donotcache_constant();

		header( 'Access-Control-Allow-Origin: *' );
	}

	/**
	 * Scheme, host and port of this site, with no path.
	 *
	 * The `.well-known` segment goes at the HOST root — before any
	 * subdirectory, never after it — so these URLs cannot be built with
	 * `home_url( $path )` on a subdirectory install without producing a path
	 * that does not exist.
	 *
	 * @return string e.g. `https://example.com` or `https://example.com:8443`.
	 */
	public static function origin(): string {
		$parts  = wp_parse_url( home_url( '/' ) );
		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return $scheme . '://' . $host . $port;
	}

	/**
	 * Every discovery URL this site serves or has served, absolute.
	 *
	 * The two bare documents, plus the RFC 9728 §3.1 path-inserted form for
	 * each server — that last one is what MCP hosts actually probe, and it
	 * carries the same cache headers, so a list that skipped it would skip
	 * the stale copy that matters.
	 *
	 * DISABLED servers are included deliberately. A caller purging after a
	 * change needs the URL of the server that was just switched OFF far more
	 * than the ones still on: that is the document which has just started
	 * lying. Filtering to enabled rows produced exactly that hole, caught by
	 * watching a real admin disable.
	 *
	 * @return array<int, string>
	 */
	public static function document_urls(): array {
		$origin = self::origin();
		$urls   = array(
			$origin . '/.well-known/oauth-authorization-server',
			$origin . '/.well-known/oauth-protected-resource',
		);

		if ( ! class_exists( '\\AcrossAI_MCP_Manager\\Includes\\Database\\MCPServer\\Query' ) ) {
			return $urls;
		}

		$servers = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
			array( 'number' => 100 )
		);

		if ( ! is_array( $servers ) ) {
			return $urls;
		}

		foreach ( $servers as $server ) {
			$namespace = ! empty( $server->server_route_namespace )
				? (string) $server->server_route_namespace
				: 'mcp';
			$route     = isset( $server->server_route ) ? (string) $server->server_route : '';

			if ( '' === $route ) {
				continue;
			}

			$resource = rest_url( trailingslashit( $namespace ) . $route );
			$path     = (string) wp_parse_url( $resource, PHP_URL_PATH );

			// On a site with plain permalinks `rest_url()` returns the
			// `?rest_route=` form, whose PATH is just `/index.php` — the route
			// lives in the query string. Appending that to the well-known
			// prefix yields `/.well-known/oauth-protected-resource/index.php`,
			// which is not a URL anything requests. Such a site cannot serve
			// the `.well-known` rewrites at all, so there is nothing here to
			// list; requiring the route to appear in the path says so without
			// having to ask the rewrite layer.
			if ( '' === $path || false === strpos( $path, $route ) ) {
				continue;
			}

			$urls[] = $origin . '/.well-known/oauth-protected-resource' . $path;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Bare issuer URL. FR-001 requires no trailing slash.
	 *
	 * @return string
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}
}
