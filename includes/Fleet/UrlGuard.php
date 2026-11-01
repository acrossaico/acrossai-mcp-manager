<?php
/**
 * Validates a hub address before this site will talk to it.
 *
 * Pairing takes a URL out of a code somebody pasted and makes this site send
 * an HTTP request to it, carrying a freshly minted credential. That is the
 * textbook shape of a server-side request forgery: an address pointing at
 * `169.254.169.254`, `127.0.0.1` or an address on the host's private network
 * turns this plugin into a probe for infrastructure the attacker cannot reach
 * directly.
 *
 * WordPress already knows how to judge that — `wp_http_validate_url()` rejects
 * loopback, private, CGNAT, link-local, multicast and reserved ranges — but
 * only reaches it through `wp_safe_remote_*`, where the check is an invisible
 * flag on the transport. Calling it here instead buys three things:
 *
 *  1. The check is a named, grep-able step a reviewer can find.
 *  2. The allowance for a deliberately-private hub can be scoped to one call
 *     and removed immediately, rather than being live across the whole
 *     transport stack — including other plugins' requests and any redirect.
 *  3. The same guard can front a parallel fan-out later, which
 *     `reject_unsafe_urls` cannot: `Requests::request_multiple()` bypasses it.
 *
 * ## The residual, stated rather than hidden
 *
 * `wp_http_validate_url()` resolves the host, then cURL resolves it again, so
 * a name that answers differently between the two calls defeats this. Closing
 * that needs pinned resolution, which the WP HTTP API does not expose. The
 * exposure is one request to an attacker-chosen address by an administrator
 * who pasted an attacker's code — already a bad position — so it is documented
 * and accepted rather than papered over.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class UrlGuard {

	/**
	 * Judge a hub URL.
	 *
	 * @since 0.4.6
	 *
	 * @param string $url           Candidate hub base URL.
	 * @param bool   $allow_private Whether the operator has accepted a private
	 *                              or internal address for this host.
	 * @return string|WP_Error The normalized URL, or why it was refused.
	 */
	public static function validate( string $url, bool $allow_private = false ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return new WP_Error(
				'acrossai_mcp_fleet_bad_url',
				__( 'That pairing code carries no address.', 'acrossai-mcp-manager' )
			);
		}

		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'acrossai_mcp_fleet_bad_url',
				__( 'That pairing code does not carry a usable web address.', 'acrossai-mcp-manager' )
			);
		}

		// HTTPS, except where WordPress itself already relaxes it. The
		// credential this site is about to send travels over this connection,
		// and core applies the same rule to Application Passwords generally —
		// so a plain-HTTP hub would be handed a password it could never use.
		if ( 'https' !== $scheme && ! self::local_development() ) {
			return new WP_Error(
				'acrossai_mcp_fleet_insecure',
				__( 'That site must use HTTPS before it can manage this one.', 'acrossai-mcp-manager' )
			);
		}

		if ( $allow_private ) {
			return $url;
		}

		// Returns the validated URL, or false. NOT a boolean true — comparing
		// against true here rejected every address, including good ones.
		if ( false === wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'acrossai_mcp_fleet_private',
				sprintf(
					/* translators: %s: the hostname from the pairing code. */
					__( '%s is a private or internal address, so this site will not send a credential to it.', 'acrossai-mcp-manager' ),
					$host
				)
			);
		}

		return $url;
	}

	/**
	 * Run one callable with a private-host allowance in place for ONE host.
	 *
	 * The allowance is added immediately before the call and removed in a
	 * `finally`, so it is never live while any other code makes a request.
	 * Matching is exact: a substring test here would let `evil-acme.com`
	 * through an allowance granted to `acme.com`.
	 *
	 * @since 0.4.6
	 *
	 * @param string   $host     Exact hostname to permit.
	 * @param callable $callback Work to run while the allowance holds.
	 * @return mixed Whatever `$callback` returns.
	 */
	public static function with_private_host_allowed( string $host, callable $callback ) {
		$host  = strtolower( $host );
		$allow = static function ( $is_external, $candidate ) use ( $host ) {
			return strtolower( (string) $candidate ) === $host ? true : $is_external;
		};
		$ports = static function ( $allowed ) {
			return array_values( array_unique( array_merge( (array) $allowed, array( 80, 443, 8080, 8443 ) ) ) );
		};

		add_filter( 'http_request_host_is_external', $allow, 10, 2 );
		add_filter( 'http_allowed_safe_ports', $ports );

		try {
			return $callback();
		} finally {
			remove_filter( 'http_request_host_is_external', $allow, 10 );
			remove_filter( 'http_allowed_safe_ports', $ports );
		}
	}

	/**
	 * Whether this install is a local development environment.
	 *
	 * The same test core applies before it will issue an Application Password
	 * at all, so the two agree by construction rather than by coincidence.
	 *
	 * @since 0.4.6
	 */
	private static function local_development(): bool {
		return 'local' === wp_get_environment_type();
	}
}
