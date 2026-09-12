<?php
/**
 * ToolPolicy — unit coverage for compose_for_row() and split_payload().
 *
 * Feature 025 (T006). Covers all 8 canonical cases from data-model.md plus
 * one hardening case per SEC-TASKS-025-1 (defense-in-depth dedup on curated
 * pick that happens to match a protocol slug).
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use WP_UnitTestCase;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ToolPolicyTest extends WP_UnitTestCase {

	private int $server_id;

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		ExposureResolver::_reset_cache_for_tests();
		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'ToolPolicyTest server',
				'server_slug'            => 'toolpolicy-test',
				'description'            => 'Seeded by ToolPolicyTest',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'toolpolicy-test',
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

	public function test_compose_all_columns_enabled_no_curated_returns_three_protocol_slugs_in_column_map_order(): void {
		$row = $this->fetch_row( array(
			'tool_discover_abilities' => 1,
			'tool_get_ability_info'   => 1,
			'tool_execute_ability'    => 1,
		) );

		$this->assertSame(
			array(
				'mcp-adapter/discover-abilities',
				'mcp-adapter/get-ability-info',
				'mcp-adapter/execute-ability',
			),
			ToolPolicy::compose_for_row( $row )
		);
	}

	public function test_compose_with_one_column_disabled_omits_that_slug(): void {
		$row = $this->fetch_row( array( 'tool_execute_ability' => 0 ) );

		$this->assertSame(
			array( 'mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info' ),
			ToolPolicy::compose_for_row( $row )
		);
	}

	public function test_compose_all_columns_disabled_no_curated_returns_empty(): void {
		$row = $this->fetch_row( array(
			'tool_discover_abilities' => 0,
			'tool_get_ability_info'   => 0,
			'tool_execute_ability'    => 0,
		) );

		$this->assertSame( array(), ToolPolicy::compose_for_row( $row ) );
	}

	public function test_compose_appends_curated_slugs_after_protocol_slugs(): void {
		MCPServerToolQuery::instance()->replace_set( $this->server_id, array( 'my-plugin/notes', 'my-plugin/tasks' ) );
		$row = $this->fetch_row();

		$composed = ToolPolicy::compose_for_row( $row );

		$this->assertContains( 'mcp-adapter/discover-abilities', $composed );
		$this->assertContains( 'my-plugin/notes', $composed );
		$this->assertContains( 'my-plugin/tasks', $composed );
		// Protocol first.
		$this->assertLessThan(
			array_search( 'my-plugin/notes', $composed, true ),
			array_search( 'mcp-adapter/discover-abilities', $composed, true )
		);
	}

	public function test_compose_dedupes_curated_pick_matching_protocol_slug(): void {
		// Defense-in-depth: split_payload should prevent this at the write
		// boundary, but if direct DB writers (or a legacy row) leave a
		// protocol slug in the curated table, compose_for_row must still
		// return exactly one occurrence.
		MCPServerToolQuery::instance()->replace_set(
			$this->server_id,
			array( 'mcp-adapter/discover-abilities' )
		);
		$row = $this->fetch_row();

		$composed = ToolPolicy::compose_for_row( $row );

		$this->assertSame(
			count( array_unique( $composed ) ),
			count( $composed ),
			'Composed list must be duplicate-free even when curated echoes a protocol slug.'
		);
	}

	public function test_split_payload_three_protocol_plus_two_curated_sets_all_columns_and_extracts_curated(): void {
		$result = ToolPolicy::split_payload( array(
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
			'my-plugin/notes',
			'my-plugin/tasks',
		) );

		$this->assertSame( 1, $result['columns']['tool_discover_abilities'] );
		$this->assertSame( 1, $result['columns']['tool_get_ability_info'] );
		$this->assertSame( 1, $result['columns']['tool_execute_ability'] );
		$this->assertEqualsCanonicalizing(
			array( 'my-plugin/notes', 'my-plugin/tasks' ),
			$result['curated']
		);
	}

	public function test_split_payload_zero_protocol_three_curated_flips_all_columns_to_zero(): void {
		$result = ToolPolicy::split_payload( array( 'a/one', 'b/two', 'c/three' ) );

		$this->assertSame( 0, $result['columns']['tool_discover_abilities'] );
		$this->assertSame( 0, $result['columns']['tool_get_ability_info'] );
		$this->assertSame( 0, $result['columns']['tool_execute_ability'] );
		$this->assertEqualsCanonicalizing(
			array( 'a/one', 'b/two', 'c/three' ),
			$result['curated']
		);
	}

	public function test_split_payload_empty_input_flips_all_columns_to_zero_and_empty_curated(): void {
		$result = ToolPolicy::split_payload( array() );

		$this->assertSame( 0, $result['columns']['tool_discover_abilities'] );
		$this->assertSame( 0, $result['columns']['tool_get_ability_info'] );
		$this->assertSame( 0, $result['columns']['tool_execute_ability'] );
		$this->assertSame( array(), $result['curated'] );
	}

	private function fetch_row( array $column_overrides = array() ): Row {
		if ( ! empty( $column_overrides ) ) {
			MCPServerQuery::instance()->update_item( $this->server_id, $column_overrides );
		}
		$rows = MCPServerQuery::instance()->query( array( 'id' => $this->server_id, 'number' => 1 ) );
		return $rows[0];
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_tools`' );
		// F026: also truncate F017 storage so per-server override rows don't
		// leak between tests. Complements ExposureResolver::_reset_cache_for_tests().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_abilities`' );
	}

	// -----------------------------------------------------------------------
	// F026 cases (post-2026-07-15 revert) — ToolPolicy::compose_effective_tools_for_row()
	//
	// F026 v1 shipped with abilities being auto-widened into tools/list at
	// server-registration time when mcp.public = true (or is_exposed = 1).
	// That widening was reverted on 2026-07-15: abilities are no longer
	// advertised directly as tools. AI clients reach them through the three
	// built-in meta tools (whose callbacks respect Abilities-tab visibility
	// via commit 070ffe2). The pre-2026-07-15 "*_includes_*_ability_*" tests
	// were inverted to pin the new behavior; the "*_excludes_*" tests still
	// hold as-is.
	// -----------------------------------------------------------------------

	public function test_compose_effective_excludes_public_ability_with_no_override_post_revert(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
		$this->register_scratch_ability( 'f026-test/public-a', true );
		$row = $this->fetch_row( array(
			'tool_discover_abilities' => 0,
			'tool_get_ability_info'   => 0,
			'tool_execute_ability'    => 0,
		) );

		$composed = ToolPolicy::compose_effective_tools_for_row( $row );

		$this->assertNotContains(
			'f026-test/public-a',
			$composed,
			'Post-2026-07-15 revert: mcp.public = true abilities are NOT widened into tools/list at registration.'
		);
	}

	public function test_compose_effective_excludes_public_ability_with_disabled_override(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
		$this->register_scratch_ability( 'f026-test/public-b', true );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'f026-test/public-b', false );
		ExposureResolver::_reset_cache_for_tests(); // Cache miss after upsert.
		$row = $this->fetch_row();

		$composed = ToolPolicy::compose_effective_tools_for_row( $row );

		$this->assertNotContains( 'f026-test/public-b', $composed );
	}

	public function test_compose_effective_excludes_non_public_ability_with_enabled_override_post_revert(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
		$this->register_scratch_ability( 'f026-test/private-c', false );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'f026-test/private-c', true );
		ExposureResolver::_reset_cache_for_tests();
		$row = $this->fetch_row();

		$composed = ToolPolicy::compose_effective_tools_for_row( $row );

		$this->assertNotContains(
			'f026-test/private-c',
			$composed,
			'Post-2026-07-15 revert: is_exposed = 1 per-server override does NOT widen tools/list either — abilities are reached through the meta tools.'
		);
	}

	public function test_compose_effective_excludes_non_public_ability_with_no_override(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
		$this->register_scratch_ability( 'f026-test/private-d', false );
		$row = $this->fetch_row( array(
			'tool_discover_abilities' => 0,
			'tool_get_ability_info'   => 0,
			'tool_execute_ability'    => 0,
		) );

		$composed = ToolPolicy::compose_effective_tools_for_row( $row );

		$this->assertNotContains( 'f026-test/private-d', $composed );
	}

	public function test_compose_effective_excludes_public_resource_typed_ability_from_tool_list(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
		// A resource-typed public ability must NOT leak into the tools composed set.
		// Guards the F026 type-filter bug fix — advertising a resource as a tool
		// causes tools/call to 404 at invocation time.
		$this->register_scratch_ability( 'f026-test/public-resource', true, 'resource' );
		$row = $this->fetch_row( array(
			'tool_discover_abilities' => 0,
			'tool_get_ability_info'   => 0,
			'tool_execute_ability'    => 0,
		) );

		$composed = ToolPolicy::compose_effective_tools_for_row( $row );

		$this->assertNotContains( 'f026-test/public-resource', $composed );
	}

	private function register_scratch_ability( string $slug, bool $mcp_public, string $type = 'tool' ): void {
		acrossai_test_register_ability(
			$slug,
			array(
				'label'       => ucfirst( basename( $slug ) ),
				'description' => 'F026 scratch ability',
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
}
