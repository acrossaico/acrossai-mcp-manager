<?php
/**
 * Client ID Metadata Document (CIMD) resolver — self-service client trust
 * gated by publisher-pinned allowlist.
 *
 * Flow: an incoming /authorize request with a URL-shaped client_id (e.g.
 * "https://claude.ai/oauth/mcp-oauth-client-metadata") is passed here BEFORE
 * ClientRepository::find_by_id() runs. If the URL's host is in the trusted
 * publisher list (CimdRegistry), we dereference it, validate the document,
 * confirm the publisher, upsert it into wp_acrossai_oauth_clients so all
 * downstream code paths (redirect_uri byte-match, per-connector settings,
 * F024 admin approval, token issuance, cascade-on-revoke) treat it exactly
 * like any other DCR-registered client — with `token_endpoint_auth_method='none'`
 * enforced and `metadata_fingerprint = sha256(canonical(doc))` for change
 * detection.
 *
 * Security invariants:
 *   1. Only trusted-publisher hosts are ever fetched (SSRF hard gate).
 *   2. Response size capped, redirects disabled, wp_safe_remote_get.
 *   3. Document's client_id MUST byte-equal the URL it was fetched from.
 *   4. Only token_endpoint_auth_method=none accepted (public client + PKCE).
 *   5. Successful resolutions cached in a transient (5m – 24h based on
 *      Cache-Control max-age); failures NEVER cached (so retries can succeed
 *      when a publisher fixes their document).
 *
 * NOT yet wired into AuthorizationController — see
 * docs/plannings/007-cimd-client-trust-layer.md for the integration point
 * and its interaction with Features 005/006.
 *
 * Adapted from wp-media/mcp-oauth's CimdResolver under GPL-3.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Row as ClientRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;

defined( 'ABSPATH' ) || exit;

final class CimdResolver {

	public const MAX_DOCUMENT_BYTES = 5120;
	public const FETCH_TIMEOUT      = 5;
	public const CACHE_PREFIX       = 'acrossai_mcp_manager_cimd_';
	public const SUPPORTED_GRANTS   = array( 'authorization_code', 'refresh_token' );

	/** @var CimdResolver|null */
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
	 * Resolve a URL-shaped client_id into a persisted ClientRow.
	 *
	 * Returns null on any failure (invalid URL, untrusted host, fetch error,
	 * validation failure, verification failure). Callers should treat null
	 * the same as "no such client" — fall through to the standard
	 * ClientRepository::find_by_id() lookup.
	 *
	 * @param string $client_id  URL-shaped client_id.
	 * @param int    $server_id  MCP server row the client is being resolved for.
	 * @return ClientRow|null
	 */
	public function resolve( string $client_id, int $server_id ): ?ClientRow {
		if ( ! CimdRegistry::looks_like_cimd_url( $client_id ) ) {
			return null;
		}

		// SSRF hard gate — never fetch a URL whose host isn't allowlisted.
		if ( ! CimdRegistry::instance()->is_trusted_host( $client_id ) ) {
			self::debug_log( 'rejected: host not in trusted-publisher allowlist', array( 'client_id' => $client_id ) );
			return null;
		}

		$cached_record = $this->cache_get( $client_id );
		if ( null !== $cached_record ) {
			return $this->upsert( $cached_record, $server_id );
		}

		$fetched = $this->fetch_document( $client_id );
		if ( null === $fetched ) {
			return null;
		}

		$record = $this->validate_document( $fetched['doc'], $client_id );
		if ( null === $record ) {
			return null;
		}

		$publisher = CimdRegistry::instance()->verify( $client_id, $fetched['doc'] );
		if ( '' === $publisher ) {
			self::debug_log( 'rejected: no trusted-publisher match', array( 'client_id' => $client_id ) );
			return null;
		}
		$record['publisher'] = $publisher;

		$this->cache_set( $client_id, $record, $fetched['ttl'] );

		return $this->upsert( $record, $server_id );
	}

	/**
	 * Upsert a resolved CIMD client into wp_acrossai_oauth_clients. Idempotent:
	 * if a row already exists for this (client_id, server_id) with the same
	 * metadata_fingerprint, no write happens. If the fingerprint changed
	 * (publisher rotated their document), the row is updated in place so the
	 * redirect_uris allowlist stays current.
	 *
	 * @param array<string, mixed> $record
	 * @param int                  $server_id
	 * @return ClientRow|null
	 */
	private function upsert( array $record, int $server_id ): ?ClientRow {
		$existing = ClientRepository::find_by_id( (string) $record['client_id'] );

		if ( null !== $existing && (int) $existing->server_id === $server_id
			&& (string) $existing->metadata_fingerprint === (string) $record['metadata_fingerprint']
		) {
			return $existing;
		}

		// TODO(F007-integration): update in place if a row exists but the
		// fingerprint changed. ClientRepository::create() will fail on
		// duplicate client_id; we need a repository update method. Left for
		// the integration PR (see docs/plannings/007-cimd-client-trust-layer.md).
		if ( null !== $existing ) {
			return $existing;
		}

		$new_id = ClientRepository::create(
			array(
				'client_id'                  => (string) $record['client_id'],
				'server_id'                  => $server_id,
				'client_secret'              => null,
				'client_name'                => (string) $record['client_name'],
				'redirect_uris'              => (array) $record['redirect_uris'],
				'grant_types'                => implode( ' ', (array) $record['grant_types'] ),
				'token_endpoint_auth_method' => 'none',
				'connector_slug'             => '',
				'metadata_fingerprint'       => (string) $record['metadata_fingerprint'],
			)
		);

		if ( 0 === $new_id ) {
			self::debug_log( 'upsert failed', array( 'client_id' => $record['client_id'] ) );
			return null;
		}

		return ClientRepository::find_by_id( (string) $record['client_id'] );
	}

	/**
	 * Fetch a metadata document with SSRF guards + size cap.
	 *
	 * @param  string $url Client ID Metadata Document URL.
	 * @return array{doc: array<string, mixed>, ttl: int}|null
	 */
	private function fetch_document( string $url ): ?array {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::FETCH_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_DOCUMENT_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::debug_log(
				'fetch failed',
				array(
					'client_id' => $url,
					'error'     => $response->get_error_message(),
				)
			);
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			self::debug_log(
				'non-200 status',
				array(
					'client_id' => $url,
					'status'    => $status,
				)
			);
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_DOCUMENT_BYTES ) {
			self::debug_log(
				'document too large',
				array(
					'client_id' => $url,
					'bytes'     => strlen( $body ),
				)
			);
			return null;
		}

		$doc = json_decode( $body, true );
		if ( ! is_array( $doc ) || empty( $doc ) ) {
			self::debug_log( 'body is not a JSON object', array( 'client_id' => $url ) );
			return null;
		}

		return array(
			'doc' => $doc,
			'ttl' => $this->parse_ttl( (string) wp_remote_retrieve_header( $response, 'cache-control' ) ),
		);
	}

	/**
	 * Validate a document + build a normalized record. Returns null on any
	 * validation failure.
	 *
	 * @param array<string, mixed> $doc
	 * @param string               $url
	 * @return array<string, mixed>|null
	 */
	private function validate_document( array $doc, string $url ): ?array {
		$doc_client_id = isset( $doc['client_id'] ) && is_string( $doc['client_id'] ) ? $doc['client_id'] : '';
		if ( '' === $doc_client_id || ! hash_equals( $url, $doc_client_id ) ) {
			self::debug_log(
				'client_id mismatch',
				array(
					'expected' => $url,
					'got'      => $doc_client_id,
				)
			);
			return null;
		}

		$auth_method = isset( $doc['token_endpoint_auth_method'] ) ? (string) $doc['token_endpoint_auth_method'] : 'none';
		if ( 'none' !== $auth_method ) {
			self::debug_log(
				'unsupported token_endpoint_auth_method',
				array(
					'client_id' => $url,
					'method'    => $auth_method,
				)
			);
			return null;
		}

		$redirect_uris = $doc['redirect_uris'] ?? array();
		if ( ! is_array( $redirect_uris ) || empty( $redirect_uris ) ) {
			self::debug_log( 'missing redirect_uris', array( 'client_id' => $url ) );
			return null;
		}
		$redirect_uris = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $redirect_uris ) ) ) );
		if ( empty( $redirect_uris ) ) {
			self::debug_log( 'no valid redirect_uris after sanitisation', array( 'client_id' => $url ) );
			return null;
		}

		$requested = isset( $doc['grant_types'] ) && is_array( $doc['grant_types'] )
			? array_map( 'strval', $doc['grant_types'] )
			: self::SUPPORTED_GRANTS;
		$grants    = array_values( array_intersect( self::SUPPORTED_GRANTS, $requested ) );
		if ( ! in_array( 'authorization_code', $grants, true ) ) {
			self::debug_log( 'authorization_code grant not offered', array( 'client_id' => $url ) );
			return null;
		}

		$client_name = isset( $doc['client_name'] ) ? sanitize_text_field( (string) $doc['client_name'] ) : '';
		if ( '' === $client_name ) {
			$client_name = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		return array(
			'client_id'                  => $url,
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => $grants,
			'token_endpoint_auth_method' => 'none',
			'metadata_fingerprint'       => self::fingerprint( $doc ),
			'publisher'                  => '',
		);
	}

	/**
	 * Deterministic sha256 fingerprint of the document. Uses PHP's
	 * JSON_UNESCAPED_SLASHES + sort_keys so a byte-identical document from
	 * a different transport (proxy re-encoding, whitespace changes) still
	 * produces the same fingerprint.
	 *
	 * @param array<string, mixed> $doc
	 */
	private static function fingerprint( array $doc ): string {
		self::ksort_recursive( $doc );
		return hash( 'sha256', (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * @param array<string, mixed> $arr
	 */
	private static function ksort_recursive( array &$arr ): void {
		ksort( $arr );
		foreach ( $arr as &$v ) {
			if ( is_array( $v ) ) {
				self::ksort_recursive( $v );
			}
		}
	}

	/**
	 * Clamp a Cache-Control max-age into a sane TTL window.
	 *
	 * @param  string $cache_control Raw Cache-Control header value.
	 * @return int Seconds, bounded to 300..DAY_IN_SECONDS.
	 */
	private function parse_ttl( string $cache_control ): int {
		$default = HOUR_IN_SECONDS;
		if ( '' !== $cache_control && preg_match( '/max-age\s*=\s*(\d+)/i', $cache_control, $m ) ) {
			$default = (int) $m[1];
		}
		return (int) max( 300, min( $default, DAY_IN_SECONDS ) );
	}

	/**
	 * Transient key for a metadata document URL.
	 *
	 * @param  string $url Document URL.
	 * @return string
	 */
	private function cache_key( string $url ): string {
		return self::CACHE_PREFIX . md5( $url );
	}

	/**
	 * Read a cached metadata document.
	 *
	 * @param  string $url Document URL.
	 * @return array<string, mixed>|null
	 */
	private function cache_get( string $url ): ?array {
		$cached = get_transient( $this->cache_key( $url ) );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Cache a metadata document.
	 *
	 * @param string               $url    Document URL.
	 * @param array<string, mixed> $record Document payload.
	 * @param int                  $ttl    Lifetime in seconds.
	 */
	private function cache_set( string $url, array $record, int $ttl ): void {
		set_transient( $this->cache_key( $url ), $record, $ttl );
	}

	/**
	 * Debug-only diagnostic; silent unless WP_DEBUG and WP_DEBUG_LOG are on.
	 *
	 * @param string               $message Log line.
	 * @param array<string, mixed> $ctx     Structured context.
	 */
	private static function debug_log( string $message, array $ctx = array() ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic.
		error_log( sprintf( '[acrossai-mcp-manager][CIMD] %s %s', $message, (string) wp_json_encode( $ctx ) ) );
	}
}
