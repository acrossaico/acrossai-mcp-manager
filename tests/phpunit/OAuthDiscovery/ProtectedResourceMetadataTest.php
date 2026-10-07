<?php
/**
 * Behavioural tests for `.well-known/oauth-protected-resource`.
 *
 * Defects 1 and 2 of B18 lived here: the document was only reachable at one
 * bare address, and it answered every caller with the FIRST enabled server
 * regardless of which resource was being asked about. A second MCP server was
 * therefore told its own identifier was some other URL, and the connector
 * could never complete OAuth.
 *
 * These call the real renderer and assert on the JSON it emits.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryController;
use AcrossAI_MCP_Manager_Test_SentJson;
use PHPUnit\Framework\TestCase;

final class ProtectedResourceMetadataTest extends TestCase {

	use ServerFixtureTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->reset_fixtures();
	}

	/**
	 * Invoke the renderer and capture what it sent.
	 *
	 * @param string $path_suffix RFC 9728 §3.1 path suffix.
	 * @return AcrossAI_MCP_Manager_Test_SentJson
	 */
	private function render( string $path_suffix = '' ): AcrossAI_MCP_Manager_Test_SentJson {
		try {
			DiscoveryController::instance()->render_protected_resource_metadata( $path_suffix );
		} catch ( AcrossAI_MCP_Manager_Test_SentJson $sent ) {
			return $sent;
		}

		self::fail( 'render_protected_resource_metadata() did not send a response.' );
	}

	public function test_path_inserted_suffix_describes_that_exact_server(): void {
		$sent = $this->render( 'wp-json/mcp/testing-servers' );

		self::assertSame(
			'https://example.test/wp-json/mcp/testing-servers',
			$sent->payload['resource'],
			'The document must describe the resource that was asked about, not the default server.'
		);
		self::assertSame( array( 'https://example.test' ), $sent->payload['authorization_servers'] );
		self::assertNull( $sent->status, 'A known resource is a 200.' );
	}

	/**
	 * The regression that broke the field: BOTH servers must get their own
	 * identifier back. Before the fix these two calls returned the same URL.
	 */
	public function test_each_server_gets_its_own_identifier(): void {
		$first  = $this->render( 'wp-json/mcp/mcp-adapter-default-server' );
		$second = $this->render( 'wp-json/mcp/testing-servers' );

		self::assertSame(
			'https://example.test/wp-json/mcp/mcp-adapter-default-server',
			$first->payload['resource']
		);
		self::assertSame(
			'https://example.test/wp-json/mcp/testing-servers',
			$second->payload['resource']
		);
		self::assertNotSame(
			$first->payload['resource'],
			$second->payload['resource'],
			'Two servers must never be advertised under one identifier.'
		);
	}

	public function test_a_third_server_also_gets_its_own_identifier(): void {
		$sent = $this->render( 'wp-json/mcp/third-server' );

		self::assertSame( 'https://example.test/wp-json/mcp/third-server', $sent->payload['resource'] );
	}

	public function test_custom_route_namespace_is_resolved(): void {
		$sent = $this->render( 'wp-json/custom-ns/vendor-server' );

		self::assertSame( 'https://example.test/wp-json/custom-ns/vendor-server', $sent->payload['resource'] );
	}

	public function test_unknown_resource_is_refused_not_echoed(): void {
		$sent = $this->render( 'wp-json/mcp/no-such-server' );

		self::assertSame( 404, $sent->status );
		self::assertSame( 'invalid_target', $sent->payload['error'] );
		self::assertArrayNotHasKey(
			'resource',
			$sent->payload,
			'Refusing must not still advertise a resource.'
		);
		self::assertSame( 404, $GLOBALS['acrossai_test_status_header'] ?? null );
	}

	public function test_bare_document_falls_back_to_the_first_enabled_server(): void {
		$sent = $this->render();

		self::assertSame(
			'https://example.test/wp-json/mcp/mcp-adapter-default-server',
			$sent->payload['resource'],
			'With no resource asked about, the anonymous default is the first enabled row.'
		);
	}

	public function test_bare_document_skips_disabled_rows(): void {
		$this->set_servers(
			array(
				$this->server( 9, 'mcp', 'disabled-server', 0 ),
				$this->server( 10, 'mcp', 'enabled-server', 1 ),
			)
		);

		$sent = $this->render();

		self::assertSame( 'https://example.test/wp-json/mcp/enabled-server', $sent->payload['resource'] );
	}

	public function test_legacy_resource_query_arg_still_works(): void {
		$_GET['resource'] = 'https://example.test/wp-json/mcp/testing-servers';

		$sent = $this->render();

		self::assertSame( 'https://example.test/wp-json/mcp/testing-servers', $sent->payload['resource'] );

		unset( $_GET['resource'] );
	}

	/**
	 * The document must not vouch for someone else's host. Before the origin
	 * pin, `?resource=` was echoed verbatim, which made this endpoint a
	 * reflection primitive that told a client its wrong URL was legitimate.
	 */
	public function test_foreign_origin_is_refused_even_when_the_path_matches(): void {
		$_GET['resource'] = 'https://evil.example/wp-json/mcp/testing-servers';

		$sent = $this->render();

		self::assertSame( 404, $sent->status );
		self::assertSame( 'invalid_target', $sent->payload['error'] );

		unset( $_GET['resource'] );
	}

	public function test_scheme_downgrade_is_refused(): void {
		$_GET['resource'] = 'http://example.test/wp-json/mcp/testing-servers';

		$sent = $this->render();

		self::assertSame( 404, $sent->status );

		unset( $_GET['resource'] );
	}

	public function test_path_suffix_wins_over_the_legacy_query_arg(): void {
		$_GET['resource'] = 'https://example.test/wp-json/mcp/mcp-adapter-default-server';

		$sent = $this->render( 'wp-json/mcp/testing-servers' );

		self::assertSame(
			'https://example.test/wp-json/mcp/testing-servers',
			$sent->payload['resource'],
			'The spec-compliant form must take precedence.'
		);

		unset( $_GET['resource'] );
	}

	public function test_traversal_in_the_suffix_is_ignored(): void {
		$sent = $this->render( 'wp-json/../../etc/passwd' );

		// Falls through to the bare-document default rather than building a URL.
		self::assertSame(
			'https://example.test/wp-json/mcp/mcp-adapter-default-server',
			$sent->payload['resource']
		);
	}

	/**
	 * WordPress installed in a subdirectory: the suffix already carries that
	 * subdirectory, so joining it to home_url() would double it.
	 */
	public function test_subdirectory_install_does_not_double_the_path(): void {
		$GLOBALS['acrossai_test_home_url'] = 'https://example.test/wp';
		$this->set_servers( array( $this->server( 1, 'mcp', 'testing-servers', 1 ) ) );

		$sent = $this->render( 'wp/wp-json/mcp/testing-servers' );

		self::assertSame( 'https://example.test/wp/wp-json/mcp/testing-servers', $sent->payload['resource'] );
	}

	public function test_authorization_server_metadata_advertises_dcr(): void {
		try {
			DiscoveryController::instance()->render_authorization_server_metadata();
			self::fail( 'render_authorization_server_metadata() did not send a response.' );
		} catch ( AcrossAI_MCP_Manager_Test_SentJson $sent ) {
			self::assertSame(
				'https://example.test/wp-json/acrossai-mcp-manager/v1/oauth/register',
				$sent->payload['registration_endpoint'],
				'Without registration_endpoint an MCP host cannot register at all.'
			);
			self::assertSame( 'https://example.test/authorize', $sent->payload['authorization_endpoint'] );
			self::assertSame( 'https://example.test/token', $sent->payload['token_endpoint'] );
		}
	}
}
