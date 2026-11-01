<?php
/**
 * Is a cache serving a discovery document this site no longer says? (Feature 101)
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot\Checks
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Three vantage points on the same URL.
 *
 *   A — built in this PHP process. No HTTP, no web server, no cache. Truth.
 *   B — fetched over HTTP carrying an Authorization header.
 *   C — fetched over HTTP with nothing attached. This is what the AI host gets.
 *
 * They should be identical. When they are not, the pattern of the difference
 * names the problem, which a single fetch never can:
 *
 *   A = B = C    nothing to report here
 *   A = B, B ≠ C a page cache is serving a stale copy to anonymous visitors
 *   A ≠ B        not a cache — something is rewriting or intercepting the
 *                request before it reaches us, for everyone
 *
 * The B/C split works because essentially every WordPress page cache bypasses
 * requests that carry credentials. That is also exactly why this bug is so
 * hard to find by hand: the operator checks in their own signed-in browser,
 * sees the corrected document, and concludes the cache is fine — while the AI
 * host, which is never signed in, keeps receiving the stale one.
 *
 * No credential is minted. The header only has to be *present* for a cache to
 * step aside; it does not have to authenticate, and this endpoint is public
 * anyway. A probe that minted an Application Password to diagnose a problem
 * would be a worse citizen than the problem.
 */
final class CacheStalenessCheck implements Check {

	/**
	 * A syntactically valid Basic credential that authenticates nobody.
	 *
	 * Caches key on the header's presence; WordPress ignores it on a public
	 * endpoint. Deliberately not a real credential — see the class docblock.
	 */
	private const BYPASS_HEADER = array( 'Authorization' => 'Basic dHJvdWJsZXNob290ZXI6cHJvYmU=' );

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'cache_staleness';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Cached discovery documents', 'acrossai-mcp-manager' );
	}

	/**
	 * @return array<int, string>
	 */
	public function requires(): array {
		return array( 'rewrite_rules', 'discovery' );
	}

	/**
	 * Run the check.
	 *
	 * @param Context $context Shared probe cache and prior results.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		foreach ( DiscoveryCheck::documents_in_process() as $document => $expected ) {
			$url = DiscoveryCheck::url( $document );

			$anonymous     = $context->fetch( $url );
			$authenticated = $context->fetch( $url, self::BYPASS_HEADER );

			$anon_body = json_decode( $anonymous['body'], true );
			$auth_body = json_decode( $authenticated['body'], true );

			if ( ! is_array( $anon_body ) || ! is_array( $auth_body ) ) {
				// The discovery check already reported this properly.
				continue;
			}

			$anon_differs = self::differing_keys( $expected, $anon_body );
			$auth_differs = self::differing_keys( $expected, $auth_body );

			if ( array() === $anon_differs && array() === $auth_differs ) {
				continue;
			}

			if ( array() !== $auth_differs ) {
				return Result::fail(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: 1: URL, 2: comma-separated field names */
						__( '%1$s returns different values from what this site generates, and it differs for signed-in requests too: %2$s. A cache that bypasses credentials would not do that, so this is not a cache — something is rewriting or intercepting this path before WordPress answers.', 'acrossai-mcp-manager' ),
						$url,
						implode( ', ', $auth_differs )
					),
					__( 'Look for a rewrite rule, a reverse proxy, or another plugin answering on this path. Purging a cache will not change this.', 'acrossai-mcp-manager' )
				);
			}

			return Result::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: URL, 2: comma-separated field names */
					__( 'A cache is serving a stale copy of %1$s to anonymous visitors. The same URL returns the current document when the request carries credentials, and the wrong one when it does not — which is exactly what an AI client receives. Stale fields: %2$s', 'acrossai-mcp-manager' ),
					$url,
					implode( ', ', $anon_differs )
				),
				__( 'Purge the page cache, then run these checks again. This plugin now asks caches not to store these documents, so once purged it should not recur; if it does, add /.well-known/oauth-authorization-server and /.well-known/oauth-protected-resource to the cache exclusion list for both your caching plugin and your host.', 'acrossai-mcp-manager' ),
				self::evidence( $anonymous['headers'] )
			);
		}

		return Result::ok(
			$this->id(),
			$this->label(),
			__( 'Both discovery documents match what this site generates, signed in and anonymously. No cache is holding a stale copy.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Field names whose value differs between the in-process document and a
	 * fetched one.
	 *
	 * Compares decoded values, not bytes: whitespace and key order are not
	 * staleness, and reporting them as such would cry wolf on every run.
	 *
	 * @param array<string, mixed> $expected Built in-process.
	 * @param array<string, mixed> $actual   Parsed from the HTTP response.
	 * @return array<int, string>
	 */
	private static function differing_keys( array $expected, array $actual ): array {
		$differing = array();

		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $actual ) || $actual[ $key ] !== $value ) {
				$differing[] = (string) $key;
			}
		}

		foreach ( array_keys( $actual ) as $key ) {
			if ( ! array_key_exists( $key, $expected ) ) {
				$differing[] = (string) $key;
			}
		}

		return array_values( array_unique( $differing ) );
	}

	/**
	 * Cache headers worth quoting back, so the verdict cites proof rather
	 * than inference, and names which layer to purge.
	 *
	 * @param array<string, string> $headers Response headers from the anonymous probe.
	 */
	private static function evidence( array $headers ): string {
		$interesting = array( 'cf-cache-status', 'x-cache', 'x-kinsta-cache', 'x-litespeed-cache', 'x-proxy-cache', 'age', 'cache-control', 'server' );

		$lines = array();
		foreach ( $interesting as $name ) {
			if ( isset( $headers[ $name ] ) && '' !== $headers[ $name ] ) {
				$lines[] = $name . ': ' . $headers[ $name ];
			}
		}

		return array() === $lines
			? ''
			: __( 'Response headers from the anonymous request:', 'acrossai-mcp-manager' ) . "\n" . implode( "\n", $lines );
	}
}
