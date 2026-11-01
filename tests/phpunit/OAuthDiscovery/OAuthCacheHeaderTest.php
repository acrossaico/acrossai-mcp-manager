<?php
/**
 * Cache-posture contract for the OAuth response paths.
 *
 * A page cache stored a customer site's `.well-known` discovery documents and
 * served them for an hour after the underlying problem was fixed. Three
 * separate things let that happen, and each gets a test here.
 *
 * These assert on source rather than on emitted headers for one reason: this
 * suite runs without WordPress, `header()` cannot be redeclared, and under the
 * CLI SAPI `headers_list()` is empty. The invariants are still precise — a
 * named constant's value, and whether a specific call precedes a specific
 * redirect — not a loose grep for a phrase.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

final class OAuthCacheHeaderTest extends TestCase {

	/** Longest a discovery document may be cached by default, in seconds. */
	private const MAX_ACCEPTABLE_TTL = 300;

	/** How many lines before a redirect the no-store call may sit. */
	private const ADJACENCY_LINES = 8;

	private function source( string $file ): string {
		$path = dirname( __DIR__, 3 ) . '/includes/OAuth/' . $file;
		self::assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	// ---- 1. we asked caches to keep discovery for an hour ----------------

	/**
	 * `public, max-age=3600` is defensible for a metadata document in
	 * isolation. In a WordPress context it tells the host edge, the CDN, the
	 * page-cache plugin and the client's own HTTP stack to hold a document
	 * that goes stale the moment a server is enabled, a route changes, or a
	 * rewrite flush lands.
	 */
	public function test_discovery_ttl_is_short(): void {
		$ttl = ( new ReflectionClassConstant(
			\AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryController::class,
			'METADATA_MAX_AGE'
		) )->getValue();

		self::assertIsInt( $ttl );
		self::assertGreaterThan( 0, $ttl, 'Zero is reachable through the filter; the default should still cache.' );
		self::assertLessThanOrEqual(
			self::MAX_ACCEPTABLE_TTL,
			$ttl,
			'A long default TTL means an operator fixes the real problem and their re-test still fails.'
		);
	}

	public function test_the_hour_long_ttl_is_gone(): void {
		self::assertStringNotContainsString(
			'max-age=3600',
			$this->source( 'DiscoveryController.php' ),
			'Reintroducing the literal hour defeats the filter and the constant both.'
		);
	}

	public function test_the_ttl_is_filterable(): void {
		self::assertStringContainsString(
			"apply_filters( 'acrossai_mcp_discovery_cache_max_age'",
			$this->source( 'DiscoveryController.php' ),
			'A site that would rather pay for the requests needs a way to say so.'
		);
	}

	// ---- 2. page caches never read our headers ---------------------------

	/**
	 * A WordPress page cache is not an HTTP cache: it stores the response
	 * before anything reads `Cache-Control`. `DONOTCACHEPAGE` is the signal
	 * nearly all of them honour, and it is the only one that reaches them.
	 * Both the success path and the `invalid_target` 404 need it — a negative
	 * answer produced during a transient bad state outlives the fix too.
	 */
	public function test_discovery_opts_out_of_page_caching(): void {
		self::assertSame(
			2,
			substr_count( $this->source( 'DiscoveryController.php' ), 'CacheHeaders::define_donotcache_constant()' ),
			'Expected the opt-out on both the metadata path and the invalid_target 404.'
		);
	}

	// ---- 3. the authorization redirects went out bare --------------------

	/**
	 * Every other OAuth response path sends `no-store`. These redirects did
	 * not, because `wp_redirect()` emits no cache headers of its own — and
	 * the success one carries `code=<authorization code>` in its `Location`.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function redirect_provider(): array {
		return array(
			'authorization code' => array( 'wp_redirect( $callback )' ),
			'error'              => array( 'wp_redirect( add_query_arg( $args, $redirect_uri ) )' ),
			'login bounce'       => array( 'wp_safe_redirect( wp_login_url( $current_url ) )' ),
		);
	}

	/**
	 * @dataProvider redirect_provider
	 *
	 * @param string $call The redirect call as written.
	 */
	public function test_redirect_sends_no_store_first( string $call ): void {
		$lines = explode( "\n", $this->source( 'AuthorizationController.php' ) );

		$at = null;
		foreach ( $lines as $i => $line ) {
			if ( false !== strpos( $line, $call ) ) {
				$at = $i;
				break;
			}
		}

		self::assertNotNull( $at, sprintf( 'Could not find "%s" — update this test alongside the rename.', $call ) );

		$before = implode( "\n", array_slice( $lines, max( 0, $at - self::ADJACENCY_LINES ), min( $at, self::ADJACENCY_LINES ) ) );

		self::assertStringContainsString(
			'CacheHeaders::send_no_store();',
			$before,
			sprintf( 'The %s redirect goes out with no cache headers at all.', $call )
		);
	}

	/**
	 * A fourth redirect added later must not slip through unguarded. Counted
	 * from statement lines only — a prose mention of `wp_redirect()` in a
	 * comment is not a redirect, and an earlier draft of this test failed on
	 * exactly that.
	 */
	public function test_no_bare_redirect_remains(): void {
		$lines = explode( "\n", $this->source( 'AuthorizationController.php' ) );

		$redirects = 0;
		$no_store  = 0;
		foreach ( $lines as $line ) {
			$trimmed = ltrim( $line );
			if ( 0 === strpos( $trimmed, 'wp_redirect(' ) || 0 === strpos( $trimmed, 'wp_safe_redirect(' ) ) {
				++$redirects;
			}
			if ( 0 === strpos( $trimmed, 'CacheHeaders::send_no_store();' ) ) {
				++$no_store;
			}
		}

		self::assertSame( 3, $redirects, 'A new redirect needs a no-store call and a row in redirect_provider().' );
		self::assertSame(
			$redirects + 1,
			$no_store,
			'One no-store per redirect, plus the consent page, which sends one without redirecting.'
		);
	}
}
