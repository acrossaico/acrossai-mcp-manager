<?php
/**
 * Tests for detecting that someone ELSE is answering our discovery URLs.
 *
 * The Site Health check previously accepted any HTTP 200 carrying valid JSON.
 * The competing `wp-media/mcp-oauth` document is well-formed JSON served with
 * a 200, so the check reported "good" on a site where Dynamic Client
 * Registration was impossible — the single most misleading signal in the B18
 * investigation. `registration_endpoint` is the fingerprint that separates the
 * two documents: only this plugin emits it.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryHealthCheck;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

final class DocumentOwnershipTest extends TestCase {

	use ServerFixtureTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->reset_fixtures();
	}

	/**
	 * @param mixed $response Fake wp_safe_remote_get() return value.
	 */
	private function is_ours( $response ): bool {
		$method = new ReflectionMethod( DiscoveryHealthCheck::class, 'document_is_ours' );
		$method->setAccessible( true );
		return (bool) $method->invoke( DiscoveryHealthCheck::instance(), $response );
	}

	/**
	 * @param array<string, mixed> $body Decoded document.
	 * @return array<string, mixed>
	 */
	private function response( array $body ): array {
		return array( 'body' => (string) wp_json_encode_compat( $body ) );
	}

	public function test_our_own_document_is_recognised(): void {
		self::assertTrue(
			$this->is_ours(
				$this->response(
					array(
						'issuer'                => 'https://example.test',
						'registration_endpoint' => 'https://example.test/wp-json/acrossai-mcp-manager/v1/oauth/register',
					)
				)
			)
		);
	}

	public function test_trailing_slash_difference_is_tolerated(): void {
		self::assertTrue(
			$this->is_ours(
				$this->response(
					array( 'registration_endpoint' => 'https://example.test/wp-json/acrossai-mcp-manager/v1/oauth/register/' )
				)
			)
		);
	}

	/**
	 * The exact document Rank Math's bundled copy serves: valid JSON, HTTP
	 * 200, and no registration endpoint at all.
	 */
	public function test_the_competing_document_is_rejected(): void {
		self::assertFalse(
			$this->is_ours(
				$this->response(
					array(
						'issuer'                                => 'https://example.test',
						'authorization_endpoint'                => 'https://example.test/oauth/authorize',
						'token_endpoint'                        => 'https://example.test/oauth/token',
						'client_id_metadata_document_supported' => true,
					)
				)
			),
			'A well-formed 200 from another plugin must not read as healthy.'
		);
	}

	public function test_a_registration_endpoint_on_another_host_is_rejected(): void {
		self::assertFalse(
			$this->is_ours(
				$this->response(
					array( 'registration_endpoint' => 'https://evil.example/wp-json/acrossai-mcp-manager/v1/oauth/register' )
				)
			)
		);
	}

	public function test_transport_error_is_not_ownership(): void {
		self::assertFalse( $this->is_ours( new WP_Error( 'http_request_failed' ) ) );
	}

	public function test_non_json_body_is_not_ownership(): void {
		self::assertFalse( $this->is_ours( array( 'body' => '<!DOCTYPE html><html>404</html>' ) ) );
	}

	public function test_empty_registration_endpoint_is_not_ownership(): void {
		self::assertFalse( $this->is_ours( $this->response( array( 'registration_endpoint' => '' ) ) ) );
	}
}

/**
 * json_encode wrapper — the suite does not load WordPress's wp_json_encode.
 *
 * @param array<string, mixed> $data Payload.
 * @return string
 */
function wp_json_encode_compat( array $data ): string {
	return (string) json_encode( $data );
}
