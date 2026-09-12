<?php
/**
 * @package AcrossAI_MCP_Manager\Tests\MCPClients
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\MCPClients;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AbstractMCPClient helpers + the six default metadata methods (F034).
 *
 * Enumeration coverage lives in GetAllRegisteredClientsTest (F034). The pre-F034
 * glob-based get_all_clients() factory + its four tests were removed as part of
 * that refactor — replaced by the filter-aware get_all_registered_clients().
 *
 * Per SC-003 this entire suite runs WITHOUT bootstrapping WordPress —
 * proof that the MCPClients module is pure service layer (FR-008).
 */
final class AbstractMCPClientTest extends TestCase {

	private function newSubject(): AbstractMCPClient {
		// Anonymous concrete subclass for invoking protected helpers.
		return new class() extends AbstractMCPClient {
			public function get_client_slug(): string {
				return 'test-stub';
			}
			public function get_client_name(): string {
				return 'Test Stub';
			}
			public function get_config_snippet( string $server_url, string $auth_token ) {
				return array(
					'url'   => $server_url,
					'token' => $this->safe_token( $auth_token ),
				);
			}
			// Expose protected helpers for testing.
			public function exposeBuildServerUrl( string $base, string $ns, string $route ): string {
				return $this->build_server_url( $base, $ns, $route );
			}
			public function exposeDeriveServerKey( string $url ): string {
				return $this->derive_server_key( $url );
			}
			public function exposeSafeToken( string $token ): string {
				return $this->safe_token( $token );
			}
			public function exposeRedactToken( string $token ): string {
				return $this->redact_token( $token );
			}
		};
	}

	// ─── derive_server_key matrix (research.md R2 + 2026-07-15 site-slug prefix) ──

	/*
	 * Expected outputs carry the `wordpress-` prefix because this test suite
	 * runs WITHOUT bootstrapping WordPress (SC-003) — `SiteSlug::get()` hits
	 * its `! function_exists( 'get_bloginfo' )` fallback branch and returns
	 * `SiteSlug::EMPTY_FALLBACK` (`'wordpress'`). Under a WP-bootstrapped
	 * env the prefix would be `sanitize_title( get_bloginfo( 'name' ) )` —
	 * see the `SiteSlugTest` case (WP-bootstrapped) for real-WP coverage.
	 *
	 * The empty-URL case still returns bare `SERVER_KEY_FALLBACK`
	 * (`'wordpress-mcp'`) because that branch short-circuits BEFORE the
	 * SiteSlug prefix is applied — the fallback is a self-contained sentinel.
	 */
	public static function deriveServerKeyMatrix(): array {
		return array(
			'empty url returns fallback'        => array( '', 'wordpress-mcp' ),
			'full rest url returns last segment' => array( 'https://example.com/wp-json/mcp/foo', 'wordpress-foo' ),
			'trailing slash stripped'           => array( 'https://example.com/wp-json/mcp/foo/', 'wordpress-foo' ),
			'query string stripped'             => array( 'https://example.com/wp-json/mcp/foo?x=1', 'wordpress-foo' ),
			'bare slug accepted'                => array( 'foo', 'wordpress-foo' ),
			// research.md R2 wart: host-only URLs return the host as the
			// key (the host IS the last path segment). Acceptable per spec.
			'host-only with slash returns host' => array( 'https://example.com/', 'wordpress-example.com' ),
			'host-only bare returns host'       => array( 'example.com', 'wordpress-example.com' ),
		);
	}

	/**
	 * @dataProvider deriveServerKeyMatrix
	 */
	public function testDeriveServerKey( string $url, string $expected ): void {
		$this->assertSame( $expected, $this->newSubject()->exposeDeriveServerKey( $url ) );
	}

	// ─── safe_token ─────────────────────────────────────────────────────────

	public function testSafeTokenReturnsPlaceholderOnEmpty(): void {
		$this->assertSame(
			'(paste generated password here)',
			$this->newSubject()->exposeSafeToken( '' )
		);
	}

	public function testSafeTokenReturnsRawOnNonEmpty(): void {
		$this->assertSame( 'xyz', $this->newSubject()->exposeSafeToken( 'xyz' ) );
		$this->assertSame(
			'abcd EFGH ijkl MNOP',
			$this->newSubject()->exposeSafeToken( 'abcd EFGH ijkl MNOP' )
		);
	}

	// ─── redact_token ───────────────────────────────────────────────────────

	public function testRedactTokenFirstFourLastTwo(): void {
		// 'abcdef5678' → 'abcd' + '…' + '78'
		$this->assertSame( 'abcd…78', $this->newSubject()->exposeRedactToken( 'abcdef5678' ) );
	}

	public function testRedactTokenEmptyReturnsEmptyMarker(): void {
		$this->assertSame( '(empty)', $this->newSubject()->exposeRedactToken( '' ) );
	}

	public function testRedactTokenShortInputDoesNotCrash(): void {
		// 4-char token: prefix is the whole thing, suffix is last 2 chars → overlap.
		// Just assert it doesn't throw and returns something non-empty.
		$result = $this->newSubject()->exposeRedactToken( 'abcd' );
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	// ─── build_server_url ───────────────────────────────────────────────────

	public function testBuildServerUrlConcatenatesPathsCorrectly(): void {
		$s = $this->newSubject();
		$this->assertSame(
			'https://example.com/wp-json/mcp/foo',
			$s->exposeBuildServerUrl( 'https://example.com/wp-json', 'mcp', 'foo' )
		);
		$this->assertSame(
			'https://example.com/wp-json/mcp/foo',
			$s->exposeBuildServerUrl( 'https://example.com/wp-json/', '/mcp/', '/foo/' )
		);
	}

	public function testBuildServerUrlHandlesEmptyNamespaceOrRoute(): void {
		$s = $this->newSubject();
		$this->assertSame(
			'https://example.com/wp-json/mcp',
			$s->exposeBuildServerUrl( 'https://example.com/wp-json', 'mcp', '' )
		);
		$this->assertSame(
			'https://example.com/wp-json/foo',
			$s->exposeBuildServerUrl( 'https://example.com/wp-json', '', 'foo' )
		);
	}

	// ─── F034 default metadata methods ──────────────────────────────────────

	/**
	 * A bare subclass implementing only the three original abstract methods
	 * (slug, name, snippet) MUST inherit empty-string defaults for the five
	 * F034 string metadata methods. This locks the backwards-compatibility
	 * invariant from FR-002: adding the abstract-level metadata contract
	 * MUST NOT break existing external subclasses.
	 */
	public function testDefaultMetadataMethodsReturnEmptyStrings(): void {
		$s = $this->newSubject();
		$this->assertSame( '', $s->get_icon(), 'get_icon() default MUST be empty string.' );
		$this->assertSame( '', $s->get_description(), 'get_description() default MUST be empty string.' );
		$this->assertSame( '', $s->get_config_file(), 'get_config_file() default MUST be empty string.' );
		$this->assertSame( '', $s->get_top_level_key(), 'get_top_level_key() default MUST be empty string.' );
		$this->assertSame( '', $s->get_instructions(), 'get_instructions() default MUST be empty string.' );
	}

	/**
	 * F034 FR-018 — default priority is 100. WP-idiomatic (matches add_action
	 * default). Third-party contributions without an explicit override sort
	 * AFTER all eight built-ins (which use 10, 20, 30, ..., 80).
	 */
	public function testDefaultPriorityReturns100(): void {
		$s = $this->newSubject();
		$this->assertSame( 100, $s->get_priority(), 'get_priority() default MUST be int 100.' );
	}
}
