<?php
/**
 * Behavioural tests for "which MCP server is this authorization for?".
 *
 * Defect 4 of B18: `server_id_from_client_and_resource()` short-circuited on
 * the client row's registration binding, so on a multi-server site every
 * authorize request was evaluated against the FIRST server a given MCP host
 * had ever connected to — wrong connector-enabled gate, wrong admin-approval
 * gate, wrong Access-Control gate, and an auth code stamped with the wrong
 * server. The token minted from it was then audience-bound to the wrong route
 * and `TokenValidator` silently dropped the bearer to anonymous.
 *
 * The RFC 8707 `resource` is the authorization target; the client's binding is
 * only a fallback. See A16.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Row as ClientRow;
use AcrossAI_MCP_Manager\Includes\OAuth\AuthorizationController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ServerResolutionTest extends TestCase {

	use ServerFixtureTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->reset_fixtures();
	}

	/**
	 * @param array<string, mixed> $fields Row overrides.
	 */
	private function client( array $fields = array() ): ClientRow {
		return new ClientRow(
			array_merge(
				array(
					'id'        => 1,
					'client_id' => 'abcdef0123456789abcdef0123456789',
					'server_id' => 0,
				),
				$fields
			)
		);
	}

	private function resolve( ClientRow $client, string $resource ): int {
		$method = new ReflectionMethod( AuthorizationController::class, 'server_id_from_client_and_resource' );
		$method->setAccessible( true );
		return (int) $method->invoke( null, $client, $resource );
	}

	public function test_resource_wins_over_the_clients_registration_binding(): void {
		// The exact field shape: Claude registered against server 1, then the
		// operator added server 2 and connected it with the same client row.
		$client = $this->client( array( 'server_id' => 1 ) );

		self::assertSame(
			2,
			$this->resolve( $client, 'https://example.test/wp-json/mcp/testing-servers' ),
			'Authorizing for server 2 must target server 2, whatever the client registered against.'
		);
	}

	public function test_a_third_server_resolves_to_itself(): void {
		$client = $this->client( array( 'server_id' => 1 ) );

		self::assertSame( 3, $this->resolve( $client, 'https://example.test/wp-json/mcp/third-server' ) );
	}

	public function test_matching_resource_and_binding_agree(): void {
		$client = $this->client( array( 'server_id' => 1 ) );

		self::assertSame( 1, $this->resolve( $client, 'https://example.test/wp-json/mcp/mcp-adapter-default-server' ) );
	}

	public function test_custom_namespace_server_resolves(): void {
		$client = $this->client();

		self::assertSame( 4, $this->resolve( $client, 'https://example.test/wp-json/custom-ns/vendor-server' ) );
	}

	public function test_falls_back_to_the_client_binding_when_the_resource_is_unresolvable(): void {
		$client = $this->client( array( 'server_id' => 7 ) );

		self::assertSame(
			7,
			$this->resolve( $client, 'https://example.test/wp-json/mcp/deleted-server' ),
			'A resource that no longer resolves must not silently become server 0.'
		);
	}

	public function test_falls_back_to_the_admin_client_id_prefix(): void {
		$client = $this->client( array( 'client_id' => 'server-5-claude-a1b2c3d4' ) );

		self::assertSame( 5, $this->resolve( $client, 'https://example.test/wp-json/mcp/deleted-server' ) );
	}

	public function test_returns_zero_when_nothing_resolves(): void {
		self::assertSame( 0, $this->resolve( $this->client(), '' ) );
	}

	public function test_resource_resolution_ignores_a_trailing_slash(): void {
		self::assertSame(
			2,
			AuthorizationController::server_id_from_resource( 'https://example.test/wp-json/mcp/testing-servers/' )
		);
	}

	public function test_resource_resolution_matches_sub_paths_of_a_server_route(): void {
		self::assertSame(
			2,
			AuthorizationController::server_id_from_resource( 'https://example.test/wp-json/mcp/testing-servers/messages' ),
			'MCP transports may address sub-paths of the server route.'
		);
	}

	public function test_resource_resolution_does_not_match_a_route_prefix_collision(): void {
		$this->set_servers(
			array(
				$this->server( 1, 'mcp', 'testing' ),
				$this->server( 2, 'mcp', 'testing-servers' ),
			)
		);

		self::assertSame(
			2,
			AuthorizationController::server_id_from_resource( 'https://example.test/wp-json/mcp/testing-servers' ),
			'"testing" must not swallow "testing-servers".'
		);
	}

	public function test_unknown_route_resolves_to_zero(): void {
		self::assertSame(
			0,
			AuthorizationController::server_id_from_resource( 'https://example.test/wp-json/mcp/nope' )
		);
	}
}
