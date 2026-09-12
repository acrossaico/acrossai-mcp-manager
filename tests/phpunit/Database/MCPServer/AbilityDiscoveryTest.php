<?php
/**
 * AbilityDiscovery — unit coverage for the type-aware F017-effective composer.
 *
 * Feature 026. Every case verifies both:
 *   (a) `mcp.type` filtering — only abilities whose `mcp.type` matches the requested
 *       type (or default 'tool' when unset) appear in the result.
 *   (b) F017 exposure resolution — row-in-table wins, else `mcp.public` fallback.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\AbilityDiscovery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use WP_UnitTestCase;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class AbilityDiscoveryTest extends WP_UnitTestCase {

	private int $server_id;

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		ExposureResolver::_reset_cache_for_tests();
		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'AbilityDiscoveryTest server',
				'server_slug'            => 'ability-discovery-test',
				'description'            => 'Seeded by AbilityDiscoveryTest',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'ability-discovery-test',
				'server_version'         => 'v1.0.0',
			)
		);
	}

	public function tearDown(): void {
		$this->truncate_tables();
		// TRUNCATE implicitly COMMITs in MySQL, so it escapes WP_UnitTestCase's
		// per-test transaction rollback and permanently removes the default
		// server row that tests/bootstrap-wp.php seeds via Activator::activate().
		// Restore it, or every later test (and later suite — they share one DB)
		// sees a table with no seeded server.
		DefaultServerSeeder::seed();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// Type filtering — each type includes only matching abilities
	// ---------------------------------------------------------------------

	public function test_for_server_tool_type_includes_only_tool_typed_public_abilities(): void {
		$this->maybe_skip();
		$this->register_scratch_ability( 'ad-test/pub-tool', true, 'tool' );
		$this->register_scratch_ability( 'ad-test/pub-resource', true, 'resource' );
		$this->register_scratch_ability( 'ad-test/pub-prompt', true, 'prompt' );

		$result = AbilityDiscovery::for_server( $this->server_id, AbilityDiscovery::TYPE_TOOL );

		$this->assertContains( 'ad-test/pub-tool', $result );
		$this->assertNotContains( 'ad-test/pub-resource', $result );
		$this->assertNotContains( 'ad-test/pub-prompt', $result );
	}

	public function test_for_server_resource_type_includes_only_resource_typed_public_abilities(): void {
		$this->maybe_skip();
		$this->register_scratch_ability( 'ad-test/pub-tool-2', true, 'tool' );
		$this->register_scratch_ability( 'ad-test/pub-resource-2', true, 'resource' );

		$result = AbilityDiscovery::for_server( $this->server_id, AbilityDiscovery::TYPE_RESOURCE );

		$this->assertContains( 'ad-test/pub-resource-2', $result );
		$this->assertNotContains( 'ad-test/pub-tool-2', $result );
	}

	public function test_for_server_prompt_type_includes_only_prompt_typed_public_abilities(): void {
		$this->maybe_skip();
		$this->register_scratch_ability( 'ad-test/pub-prompt-3', true, 'prompt' );
		$this->register_scratch_ability( 'ad-test/pub-tool-3', true, 'tool' );

		$result = AbilityDiscovery::for_server( $this->server_id, AbilityDiscovery::TYPE_PROMPT );

		$this->assertContains( 'ad-test/pub-prompt-3', $result );
		$this->assertNotContains( 'ad-test/pub-tool-3', $result );
	}

	// ---------------------------------------------------------------------
	// F017 override precedence for resources/prompts
	// ---------------------------------------------------------------------

	public function test_for_server_resource_type_honors_is_exposed_zero_override(): void {
		$this->maybe_skip();
		$this->register_scratch_ability( 'ad-test/public-resource-off', true, 'resource' );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'ad-test/public-resource-off', false );
		ExposureResolver::_reset_cache_for_tests();

		$result = AbilityDiscovery::for_server( $this->server_id, AbilityDiscovery::TYPE_RESOURCE );

		$this->assertNotContains( 'ad-test/public-resource-off', $result );
	}

	public function test_for_server_prompt_type_honors_is_exposed_one_override_for_non_public(): void {
		$this->maybe_skip();
		$this->register_scratch_ability( 'ad-test/private-prompt-on', false, 'prompt' );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'ad-test/private-prompt-on', true );
		ExposureResolver::_reset_cache_for_tests();

		$result = AbilityDiscovery::for_server( $this->server_id, AbilityDiscovery::TYPE_PROMPT );

		$this->assertContains( 'ad-test/private-prompt-on', $result );
	}

	// ---------------------------------------------------------------------
	// Missing mcp.type defaults to 'tool' (vendor semantic)
	// ---------------------------------------------------------------------

	public function test_for_server_tool_type_includes_public_ability_without_explicit_type(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
		// Register with meta.mcp.public=true but NO mcp.type — should default to 'tool'.
		acrossai_test_register_ability(
			'ad-test/no-type',
			array(
				'label'       => 'No Type',
				'description' => 'default-type test',
				'category'    => 'test',
				'meta'        => array( 'mcp' => array( 'public' => true ) ),
				'input_schema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'execute_callback' => static fn () => array(),
			)
		);

		$this->assertContains( 'ad-test/no-type', AbilityDiscovery::for_server( $this->server_id, AbilityDiscovery::TYPE_TOOL ) );
		$this->assertNotContains( 'ad-test/no-type', AbilityDiscovery::for_server( $this->server_id, AbilityDiscovery::TYPE_RESOURCE ) );
	}

	// ---------------------------------------------------------------------
	// Result normalization
	// ---------------------------------------------------------------------

	public function test_for_server_returns_deduped_string_normalized_zero_indexed_array(): void {
		$this->maybe_skip();
		$this->register_scratch_ability( 'ad-test/dup-a', true, 'tool' );
		$this->register_scratch_ability( 'ad-test/dup-b', true, 'tool' );

		$result = AbilityDiscovery::for_server( $this->server_id, AbilityDiscovery::TYPE_TOOL );

		$this->assertSame( array_values( $result ), $result, 'Result must be a zero-indexed list.' );
		$this->assertSame( count( array_unique( $result ) ), count( $result ), 'Result must be deduped.' );
		foreach ( $result as $slug ) {
			$this->assertIsString( $slug );
		}
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function maybe_skip(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
	}

	private function register_scratch_ability( string $slug, bool $mcp_public, string $type ): void {
		acrossai_test_register_ability(
			$slug,
			array(
				'label'       => ucfirst( basename( $slug ) ),
				'description' => 'F026 AbilityDiscovery test',
				'category'    => 'test',
				'meta'        => array(
					'mcp' => array(
						'public' => $mcp_public,
						'type'   => $type,
					),
				),
				'input_schema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'execute_callback' => static fn () => array(),
			)
		);
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_abilities`' );
	}
}
