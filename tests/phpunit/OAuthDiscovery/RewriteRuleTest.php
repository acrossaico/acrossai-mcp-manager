<?php
/**
 * Behavioural tests for the OAuth rewrite rules.
 *
 * Defect 1 of B18: only the bare `.well-known` paths were routable, so the
 * RFC 9728 §3.1 path-inserted URL that MCP hosts probe returned a WordPress
 * 404 and the host fell back to the (wrong) bare document.
 *
 * Registration ORDER is asserted because it is load-bearing: WordPress
 * matches `extra_rules_top` in insertion order, so a bare rule registered
 * first would shadow nothing today but would the moment either pattern is
 * relaxed.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use AcrossAI_MCP_Manager\Includes\OAuth\OAuthRouter;
use PHPUnit\Framework\TestCase;

final class RewriteRuleTest extends TestCase {

	use ServerFixtureTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->reset_fixtures();
		OAuthRouter::instance()->register_rewrite_rules();
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function rules(): array {
		return $GLOBALS['acrossai_test_rewrite_rules'];
	}

	/**
	 * @param string $needle Substring of the regex.
	 * @return int Index in registration order, or -1.
	 */
	private function index_of( string $needle ): int {
		foreach ( $this->rules() as $i => $rule ) {
			if ( false !== strpos( $rule['regex'], $needle ) ) {
				return $i;
			}
		}
		return -1;
	}

	public function test_path_inserted_protected_resource_rule_is_registered(): void {
		self::assertGreaterThan(
			-1,
			$this->index_of( 'oauth-protected-resource/(.+?)' ),
			'Without this rule the URL every MCP host probes is a 404.'
		);
	}

	/**
	 * RFC 8414's path-insertion form applies only to issuers that HAVE a path
	 * component. `DiscoveryController::issuer()` is `untrailingslashit(
	 * home_url() )` — always path-less — so no such rule is applicable to us.
	 *
	 * 0.9.12 registered one anyway, for symmetry with the resource rule, and
	 * it discarded its own capture: every `/.well-known/oauth-authorization-server/<anything>`
	 * on the domain got THIS site's metadata with `issuer` set to our root.
	 * That squats on any other plugin serving its own AS discovery (observed
	 * against Bit CRM on a live site) and is the same wrong D21 records Rank
	 * Math doing to us. Removed in 0.9.13; this asserts it stays removed.
	 */
	public function test_no_path_inserted_authorization_server_rule_is_registered(): void {
		self::assertSame(
			-1,
			$this->index_of( 'oauth-authorization-server/(.+?)' ),
			'A catch-all AS rule answers for other plugins\' issuers. Do not reintroduce it.'
		);
	}

	public function test_path_inserted_rule_is_registered_before_the_bare_one(): void {
		self::assertLessThan(
			$this->index_of( "oauth-protected-resource/?$" ),
			$this->index_of( 'oauth-protected-resource/(.+?)' ),
			'WordPress matches extra_rules_top in insertion order; specific must precede general.'
		);
	}

	/**
	 * The bare AS rule is the only correct one for a path-less issuer, and it
	 * must keep working — it is step 3 of the discovery chain, reached because
	 * the protected-resource document names the site root as its
	 * authorization server.
	 */
	public function test_bare_authorization_server_rule_is_still_registered(): void {
		self::assertGreaterThan( -1, $this->index_of( "oauth-authorization-server/?$" ) );
	}

	public function test_every_rule_is_registered_at_the_top(): void {
		foreach ( $this->rules() as $rule ) {
			self::assertSame(
				'top',
				$rule['after'],
				'A bottom rule loses to any plugin that registers at the top — which is exactly how the bundled wp-media implementation won this site.'
			);
		}
	}

	public function test_path_inserted_rule_passes_the_suffix_through_to_the_query_var(): void {
		$rule = null;
		foreach ( $this->rules() as $candidate ) {
			if ( false !== strpos( $candidate['regex'], 'oauth-protected-resource/(.+?)' ) ) {
				$rule = $candidate;
				break;
			}
		}

		self::assertNotNull( $rule );
		self::assertStringContainsString( 'pr-metadata', $rule['query'] );
		self::assertStringContainsString(
			OAuthRouter::RESOURCE_VAR . '=$matches[1]',
			$rule['query'],
			'The captured path must reach the controller or per-resource discovery silently degrades to the bare document.'
		);
	}

	public function test_resource_query_var_is_whitelisted(): void {
		$vars = OAuthRouter::instance()->add_query_var( array() );

		self::assertContains(
			OAuthRouter::RESOURCE_VAR,
			$vars,
			'An un-whitelisted query var is dropped by WP before parse_request sees it.'
		);
	}

	/**
	 * The regexes must actually match the URLs hosts request. Asserting the
	 * pattern string alone would not catch an anchoring mistake.
	 */
	public function test_regexes_match_the_urls_mcp_hosts_actually_request(): void {
		$suffix_rule = null;
		$bare_rule   = null;
		foreach ( $this->rules() as $rule ) {
			if ( false !== strpos( $rule['regex'], 'oauth-protected-resource/(.+?)' ) ) {
				$suffix_rule = $rule['regex'];
			}
			if ( false !== strpos( $rule['regex'], "oauth-protected-resource/?$" ) ) {
				$bare_rule = $rule['regex'];
			}
		}

		$path = '.well-known/oauth-protected-resource/wp-json/mcp/testing-servers';

		self::assertSame( 1, preg_match( '#' . $suffix_rule . '#', $path, $m ) );
		self::assertSame( 'wp-json/mcp/testing-servers', $m[1] );

		self::assertSame(
			0,
			preg_match( '#' . $bare_rule . '#', $path ),
			'The bare rule must not swallow the path-inserted URL.'
		);
		self::assertSame(
			1,
			preg_match( '#' . $bare_rule . '#', '.well-known/oauth-protected-resource' ),
			'The bare rule must still serve the anonymous default document.'
		);
	}
}
