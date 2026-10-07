<?php
/**
 * Trusted-publisher registry for Client ID Metadata Document (CIMD) trust.
 *
 * CIMD lets a client identify itself with an HTTPS URL that dereferences to
 * its own metadata document, replacing the pre-registered-client pattern of
 * RFC 7591 DCR. Because the authorize endpoint is unauthenticated, we do NOT
 * dereference arbitrary URLs: this registry gates which publisher hosts are
 * ever fetched, and pins the exact client_id URLs that are considered trusted
 * for each publisher.
 *
 * Claude is bundled. Add your own via the `acrossai_mcp_manager_cimd_trusted_publishers`
 * filter — see the docblock on `get_all()` for the shape.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class CimdRegistry {

	/** @var CimdRegistry|null */
	private static $instance = null;

	/**
	 * Private constructor — singleton per the Module Contract.
	 */
	private function __construct() {
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
	 * Whether a candidate client_id URL is HTTPS-shaped enough to be worth
	 * resolving. Does NOT check the trust list — that is `is_trusted_host()`.
	 *
	 * @param string $client_id Untrusted candidate.
	 * @return bool
	 */
	public static function looks_like_cimd_url( string $client_id ): bool {
		if ( '' === $client_id ) {
			return false;
		}

		$parts = wp_parse_url( $client_id );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		if ( 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return false;
		}
		if ( empty( $parts['host'] ) ) {
			return false;
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $path || '/' === $path ) {
			return false;
		}
		if ( isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * True if $client_id's host matches ANY trusted publisher host. Used as
	 * the SSRF allowlist before the network fetch runs.
	 *
	 * @param string $client_id
	 * @return bool
	 */
	public function is_trusted_host( string $client_id ): bool {
		$host = (string) wp_parse_url( $client_id, PHP_URL_HOST );
		if ( '' === $host ) {
			return false;
		}

		foreach ( $this->get_all() as $config ) {
			if ( (string) ( $config['host'] ?? '' ) === $host ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Verify a fetched CIMD document against the trusted-publisher allowlist.
	 * Returns the publisher slug on success, empty string on failure.
	 *
	 * @param string               $client_id The URL the document was fetched from.
	 * @param array<string, mixed> $doc       The decoded, URL-shape-validated metadata document.
	 * @return string Publisher slug (e.g. 'claude') or '' if untrusted.
	 */
	public function verify( string $client_id, array $doc ): string {
		foreach ( $this->get_all() as $slug => $config ) {
			if ( $this->matches_publisher( $client_id, $doc, $config ) ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/**
	 * Filtered trusted-publisher allowlist. Each entry MUST have:
	 *   - client_ids (string[]): exact URLs trusted for this publisher.
	 *   - host (string): hostname client_ids must resolve to (SSRF gate).
	 *
	 * @return array<string, array{client_ids: array<int, string>, host: string}>
	 */
	public function get_all(): array {
		$publishers = array(
			'claude' => array(
				'client_ids' => array(
					'https://claude.ai/oauth/claude-code-client-metadata',
					'https://claude.ai/oauth/mcp-oauth-client-metadata',
				),
				'host'       => 'claude.ai',
			),
		);

		/**
		 * Filter the CIMD trusted-publisher allowlist.
		 *
		 * This filter runs server-side with no request-derived input. It can
		 * only ADD publishers; it cannot bypass the exact-client_id match or
		 * the redirect_uri byte-match that AuthorizationController performs.
		 *
		 * @param array<string, array{client_ids: array<int, string>, host: string}> $publishers
		 * @return array<string, array{client_ids: array<int, string>, host: string}>
		 * @since 0.6.0
		 */
		$filtered = apply_filters( 'acrossai_mcp_manager_cimd_trusted_publishers', $publishers );

		return is_array( $filtered ) ? $filtered : $publishers;
	}

	/**
	 * @param string               $client_id
	 * @param array<string, mixed> $doc
	 * @param array<string, mixed> $config
	 */
	private function matches_publisher( string $client_id, array $doc, array $config ): bool {
		$client_ids = (array) ( $config['client_ids'] ?? array() );

		// 1. Exact client_id must be in the pinned list (primary trust anchor).
		if ( ! in_array( $client_id, $client_ids, true ) ) {
			return false;
		}

		// 2. Host must match the publisher's host (defense in depth).
		$host = (string) wp_parse_url( $client_id, PHP_URL_HOST );
		if ( '' === $host || (string) ( $config['host'] ?? '' ) !== $host ) {
			return false;
		}

		// 3. Public-client flow only. Redirect URI byte-match happens in
		// AuthorizationController::assert_redirect_uri_or_die — pinning it
		// here would break verification when a publisher registers extra
		// redirect_uris for sibling products.
		$auth_method = (string) ( $doc['token_endpoint_auth_method'] ?? 'none' );
		return 'none' === $auth_method;
	}
}
