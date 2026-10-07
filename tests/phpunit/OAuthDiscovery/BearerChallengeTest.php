<?php
/**
 * Behavioural tests for the `WWW-Authenticate` challenge on a 401.
 *
 * Two B18 problems live here. The challenge advertised the legacy
 * `?resource=` metadata URL rather than the RFC 9728 §3.1 path-inserted one,
 * and eligibility was decided by `strpos( $route, '/mcp' ) === 0` — a prefix
 * test that both over-matched (any namespace merely starting with "mcp") and
 * under-matched: a database-registered server on a custom route namespace got
 * no challenge at all, leaving the client with nothing to discover.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use AcrossAI_MCP_Manager\Includes\OAuth\BearerChallengeHeader;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

final class BearerChallengeTest extends TestCase {

	use ServerFixtureTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->reset_fixtures();
	}

	/**
	 * @param string $route  REST route.
	 * @param int    $status Response status.
	 * @return WP_REST_Response
	 */
	private function challenge( string $route, int $status = 401 ): WP_REST_Response {
		$response = new WP_REST_Response( $status );

		BearerChallengeHeader::instance()->add_bearer_challenge(
			$response,
			null,
			new WP_REST_Request( $route )
		);

		return $response;
	}

	public function test_challenge_advertises_the_path_inserted_metadata_url(): void {
		$response = $this->challenge( '/mcp/testing-servers' );

		self::assertSame(
			'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource/wp-json/mcp/testing-servers"',
			$response->sent_headers['WWW-Authenticate'] ?? null
		);
	}

	public function test_each_server_is_challenged_with_its_own_metadata_url(): void {
		$first  = $this->challenge( '/mcp/mcp-adapter-default-server' );
		$second = $this->challenge( '/mcp/testing-servers' );

		self::assertNotSame(
			$first->sent_headers['WWW-Authenticate'],
			$second->sent_headers['WWW-Authenticate'],
			'Two servers pointing a client at one metadata document is the whole bug.'
		);
		self::assertStringEndsWith(
			'/wp-json/mcp/mcp-adapter-default-server"',
			$first->sent_headers['WWW-Authenticate']
		);
	}

	/**
	 * The under-match: a DB server may use any `server_route_namespace`.
	 */
	public function test_custom_route_namespace_still_gets_a_challenge(): void {
		$response = $this->challenge( '/custom-ns/vendor-server' );

		self::assertSame(
			'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource/wp-json/custom-ns/vendor-server"',
			$response->sent_headers['WWW-Authenticate'] ?? null,
			'Before the fix this route got no challenge at all.'
		);
	}

	/**
	 * The over-match: `/mcp-adapter/...` starts with "/mcp" but is not an MCP
	 * server route. It must not be challenged just for sharing a prefix.
	 */
	public function test_unrelated_route_sharing_the_mcp_prefix_is_not_challenged(): void {
		$response = $this->challenge( '/mcp-adapter/v1/settings' );

		self::assertArrayNotHasKey( 'WWW-Authenticate', $response->sent_headers );
	}

	public function test_non_mcp_route_is_not_challenged(): void {
		$response = $this->challenge( '/wp/v2/posts' );

		self::assertArrayNotHasKey( 'WWW-Authenticate', $response->sent_headers );
	}

	public function test_non_401_response_is_untouched(): void {
		foreach ( array( 200, 403, 500 ) as $status ) {
			$response = $this->challenge( '/mcp/testing-servers', $status );
			self::assertArrayNotHasKey(
				'WWW-Authenticate',
				$response->sent_headers,
				'A challenge only belongs on a 401.'
			);
		}
	}

	/**
	 * A server row can be deleted while a stale client keeps polling. The
	 * `/mcp/` literal fallback keeps such routes discoverable rather than
	 * silently dropping the challenge.
	 */
	public function test_unknown_route_under_the_mcp_namespace_still_gets_a_challenge(): void {
		$response = $this->challenge( '/mcp/deleted-server' );

		self::assertStringContainsString(
			'/wp-json/mcp/deleted-server',
			$response->sent_headers['WWW-Authenticate'] ?? ''
		);
	}

	public function test_empty_route_is_ignored(): void {
		$response = $this->challenge( '' );

		self::assertArrayNotHasKey( 'WWW-Authenticate', $response->sent_headers );
	}
}
