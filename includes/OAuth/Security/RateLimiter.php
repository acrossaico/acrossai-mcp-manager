<?php
/**
 * Transient-backed rate limiter (Feature 021).
 *
 * FR-027 (10/IP/60s for /register) + FR-028 (60/IP/60s for /authorize + /token).
 * SEC-021-003 IP determination honours `acrossai_mcp_manager_trusted_proxies`
 * filter (FR-044). Non-distributed — see spec.md §Assumptions.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth\Security
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth\Security;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	private const TRANSIENT_PREFIX = 'acrossai_mcp_oauth_rl_';

	/**
	 * Check + increment. Returns true if under limit, `\WP_Error` if over.
	 *
	 * @param string $bucket Identifier (e.g., 'dcr', 'authorize', 'token').
	 * @param string $ip     Client IP (from self::client_ip()).
	 * @param int    $max    Max requests per window.
	 * @param int    $window Window seconds.
	 * @return true|\WP_Error `true` on pass, `WP_Error( 'slow_down', ... )` on lockout.
	 */
	public static function check( string $bucket, string $ip, int $max, int $window ) {
		$key   = self::key( $bucket, $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return new \WP_Error(
				'slow_down',
				__( 'Rate limit exceeded; retry after the window resets.', 'acrossai-mcp-manager' ),
				array(
					'status'          => 429,
					'retry_after_sec' => $window,
				)
			);
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Determine the client IP for rate-limit bucketing (FR-044 / SEC-021-003).
	 *
	 * Default behaviour: `$_SERVER['REMOTE_ADDR']`. Operators behind a
	 * reverse proxy set `acrossai_mcp_manager_trusted_proxies` to an array
	 * of CIDR strings; when REMOTE_ADDR is in that list, the rightmost
	 * X-Forwarded-For entry not itself in the list is used. Never trust
	 * X-Forwarded-For unconditionally.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		/**
		 * Filter: acrossai_mcp_manager_trusted_proxies
		 *
		 * @param array<int, string> $proxies List of CIDR strings.
		 */
		$trusted_proxies = (array) apply_filters( 'acrossai_mcp_manager_trusted_proxies', array() );

		if ( empty( $trusted_proxies ) || ! self::ip_matches_any_cidr( $remote, $trusted_proxies ) ) {
			return $remote;
		}

		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
		if ( '' === $xff ) {
			return $remote;
		}

		$candidates = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			if ( ! self::ip_matches_any_cidr( $candidate, $trusted_proxies ) ) {
				return $candidate;
			}
		}

		return $remote;
	}

	/**
	 * Hash the IP for transient key stability (avoid raw IP in options table).
	 *
	 * @param string $bucket Bucket name.
	 * @param string $ip     Determined IP.
	 * @return string
	 */
	private static function key( string $bucket, string $ip ): string {
		return self::TRANSIENT_PREFIX . $bucket . '_' . substr( hash( 'sha256', $ip ), 0, 16 );
	}

	/**
	 * True iff $ip is within any CIDR block in $cidrs. Supports IPv4 + IPv6.
	 *
	 * @param string             $ip    Address to test.
	 * @param array<int, string> $cidrs List of CIDRs.
	 * @return bool
	 */
	private static function ip_matches_any_cidr( string $ip, array $cidrs ): bool {
		if ( '' === $ip ) {
			return false;
		}

		foreach ( $cidrs as $cidr ) {
			if ( ! is_string( $cidr ) ) {
				continue;
			}
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IPv4/IPv6 CIDR containment check.
	 *
	 * @param string $ip   Address.
	 * @param string $cidr CIDR notation ('10.0.0.0/8', 'fd00::/8', or a bare IP).
	 * @return bool
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( false === strpos( $cidr, '/' ) ) {
			return hash_equals( $cidr, $ip );
		}

		list( $subnet, $prefix_length ) = explode( '/', $cidr, 2 );
		$prefix_length                  = (int) $prefix_length;

		$ip_bin     = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton returns false on invalid input; guard immediately after.
		$subnet_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$byte_count     = intdiv( $prefix_length, 8 );
		$remainder_bits = $prefix_length % 8;
		$ip_prefix      = substr( $ip_bin, 0, $byte_count );
		$subnet_prefix  = substr( $subnet_bin, 0, $byte_count );

		if ( ! hash_equals( $subnet_prefix, $ip_prefix ) ) {
			return false;
		}

		if ( 0 === $remainder_bits ) {
			return true;
		}

		$mask        = chr( ( 0xFF << ( 8 - $remainder_bits ) ) & 0xFF );
		$ip_byte     = substr( $ip_bin, $byte_count, 1 );
		$subnet_byte = substr( $subnet_bin, $byte_count, 1 );

		return ( $ip_byte & $mask ) === ( $subnet_byte & $mask );
	}
}
