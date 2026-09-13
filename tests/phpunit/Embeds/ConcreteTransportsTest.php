<?php
/**
 * F037 — 3 built-in concrete transport classes shape check.
 *
 * Data-provider parameterized over NpmEmbedTransport, ClientEmbedTransport,
 * AiConnectorEmbedTransport. Guards against class rename / key drift /
 * priority-slot collision (SC-007 automated regression).
 *
 * Runs under the `embeds` suite with WP bootstrap.
 *
 * @package AcrossAI_MCP_Manager\Tests\Embeds
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Embeds;

use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use AcrossAI_MCP_Manager\Includes\Embeds\AiConnectorEmbedTransport;
use AcrossAI_MCP_Manager\Includes\Embeds\ClientEmbedTransport;
use AcrossAI_MCP_Manager\Includes\Embeds\NpmEmbedTransport;
use ReflectionClass;
use WP_UnitTestCase;

final class ConcreteTransportsTest extends WP_UnitTestCase {

	/**
	 * @return array<string, array{class-string, string, string, int}>
	 */
	public static function provide_built_in_transports(): array {
		return array(
			'npm'          => array( NpmEmbedTransport::class, 'npm', 'NPM Methods', 10 ),
			'client'       => array( ClientEmbedTransport::class, 'client', 'MCP Clients', 20 ),
			'ai_connector' => array( AiConnectorEmbedTransport::class, 'ai_connector', 'AI Connectors', 30 ),
		);
	}

	/**
	 * @dataProvider provide_built_in_transports
	 */
	public function test_transport_extends_abstract_base( string $fqn, string $key, string $label, int $priority ): void {
		unset( $key, $label, $priority );
		$this->assertTrue( class_exists( $fqn ) );
		$this->assertTrue( is_subclass_of( $fqn, AbstractEmbedTransport::class ) );
	}

	/**
	 * @dataProvider provide_built_in_transports
	 */
	public function test_transport_class_is_final( string $fqn, string $key, string $label, int $priority ): void {
		unset( $key, $label, $priority );
		$reflection = new ReflectionClass( $fqn );
		$this->assertTrue( $reflection->isFinal(), 'F037 subclass MUST be final per D36 (extension via filter, not subclass).' );
	}

	/**
	 * @dataProvider provide_built_in_transports
	 */
	public function test_transport_key_matches_expected( string $fqn, string $key, string $label, int $priority ): void {
		unset( $label, $priority );
		$instance = new $fqn();
		$this->assertSame( $key, $instance->get_transport_key() );
	}

	/**
	 * @dataProvider provide_built_in_transports
	 */
	public function test_transport_key_matches_regex( string $fqn, string $key, string $label, int $priority ): void {
		unset( $key, $label, $priority );
		$instance = new $fqn();
		// Regex includes underscore per B1 post-implementation bugfix — F035
		// DTO `category` field values (`ai_connector`) require it. Without
		// this fix, AiConnectorEmbedTransport is silently dropped at runtime.
		$this->assertMatchesRegularExpression( '/\A[a-z0-9_-]{1,64}\z/', $instance->get_transport_key() );
	}

	/**
	 * @dataProvider provide_built_in_transports
	 */
	public function test_transport_label_matches_expected( string $fqn, string $key, string $label, int $priority ): void {
		unset( $key, $priority );
		$instance = new $fqn();
		$this->assertSame( $label, $instance->get_checkbox_label() );
	}

	/**
	 * @dataProvider provide_built_in_transports
	 */
	public function test_transport_label_is_non_empty_string( string $fqn, string $key, string $label, int $priority ): void {
		unset( $key, $label, $priority );
		$instance = new $fqn();
		$this->assertIsString( $instance->get_checkbox_label() );
		$this->assertNotEmpty( $instance->get_checkbox_label() );
	}

	/**
	 * @dataProvider provide_built_in_transports
	 */
	public function test_transport_priority_matches_expected( string $fqn, string $key, string $label, int $priority ): void {
		unset( $key, $label );
		$instance = new $fqn();
		$this->assertSame( $priority, $instance->get_priority() );
	}
}
