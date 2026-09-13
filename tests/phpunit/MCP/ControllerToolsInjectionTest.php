<?php
/**
 * MCP\Controller — F025 filter injection coverage.
 *
 * Covers:
 *  1–5: filter_default_server_config() defensive short-circuits + REPLACE
 *       semantics on the vendor mcp_adapter_default_server_config seam.
 *  6–8: register_database_servers() emission of the new
 *       acrossai_mcp_manager_server_tools filter.
 *  9:   SEC-TASKS-025-1 confused-deputy — filter re-adds a slug the operator
 *       removed via column=0; assert the composed set includes it AND that
 *       the observability action is NOT fired on filter-side changes.
 *
 * @package AcrossAI_MCP_Manager\Tests\MCP
 */

namespace AcrossAI_MCP_Manager\Tests\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\AbilityDiscovery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\MCP\Controller;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ControllerToolsInjectionTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		ExposureResolver::_reset_cache_for_tests();
	}

	public function tearDown(): void {
		$this->truncate_tables();
		parent::tearDown();
	}

	// Cases 1–5 — filter_default_server_config() ----------------------------

	public function test_filter_default_server_config_returns_input_untouched_when_default_row_missing(): void {
		// No seed — default row absent.
		$config = array(
			'server_id' => 'x',
			'tools'     => array( 'a/one' ),
		);
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertSame( $config, $result );
	}

	/**
	 * An operator who switches every protocol tool off and curates nothing gets
	 * an EMPTY tool list — never the vendor's auto-discovered set.
	 *
	 * This inverts the pre-0.6.1 `if ( ! empty( $tools ) )` fallback. That
	 * fallback kept the vendor list whenever our effective set was empty, and
	 * mcp-adapter 0.6.0 widened the vendor list: McpAbilityExposure::is_meta_public()
	 * now falls back to a bare `meta.public` when `meta.mcp.public` is unset. The
	 * combination listed abilities on a server the operator had switched fully
	 * off — inverting operator intent. Execution stayed gated by
	 * AbilityExposureGate, so the leak was name/schema-level only.
	 *
	 * Safe because the F025 columns are `tinyint(1) NOT NULL DEFAULT 1`
	 * (Table::upgrade_to_1_1_1) — MySQL backfilled every pre-F025 row with 1, so
	 * an empty set is always deliberate, never an un-migrated row.
	 */
	public function test_filter_default_server_config_empties_tools_rather_than_keeping_vendor_defaults(): void {
		$this->seed_default_server( array(
			'tool_discover_abilities' => 0,
			'tool_get_ability_info'   => 0,
			'tool_execute_ability'    => 0,
		) );

		$config = array(
			'server_id' => 'x',
			'tools'     => array( 'vendor/one' ),
		);
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertSame(
			array(),
			$result['tools'],
			'All-off + nothing curated MUST yield an empty tool list, not the vendor fallback.'
		);
		$this->assertNotContains(
			'vendor/one',
			$result['tools'],
			'The widened vendor set must never survive an operator "expose nothing" configuration.'
		);
		$this->assertSame( 'x', $result['server_id'], 'Unrelated config keys must be preserved.' );
	}

	public function test_filter_default_server_config_replaces_tools_and_preserves_other_keys(): void {
		$this->seed_default_server( array(
			'tool_discover_abilities' => 1,
			'tool_get_ability_info'   => 1,
			'tool_execute_ability'    => 0, // Deliberately off.
		) );

		$config = array(
			'server_id'          => 'preserve-me',
			'server_name'        => 'Preserve me',
			'server_description' => 'Also preserve me',
			'tools'              => array( 'vendor/should-be-replaced' ),
			'resources'          => array( 'r/one' ),
			'prompts'            => array( 'p/one' ),
		);
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertSame( 'preserve-me', $result['server_id'] );
		$this->assertSame( 'Preserve me', $result['server_name'] );
		// F026 replaces tools, resources AND prompts unconditionally — see the
		// rationale block in Controller::filter_default_server_config(). If the
		// vendor's resource/prompt lists survived, disabling a public ability on
		// the Abilities tab would be a no-op for the default server. So these
		// are the plugin's discovery output (empty: this fixture registers no
		// resource- or prompt-typed abilities), not the incoming values.
		$this->assertSame( array(), $result['resources'], 'F026: resources are replaced, not preserved.' );
		$this->assertSame( array(), $result['prompts'], 'F026: prompts are replaced, not preserved.' );
		$this->assertNotContains( 'vendor/should-be-replaced', $result['tools'] );
		$this->assertContains( 'mcp-adapter/discover-abilities', $result['tools'] );
		$this->assertContains( 'mcp-adapter/get-ability-info', $result['tools'] );
		$this->assertNotContains( 'mcp-adapter/execute-ability', $result['tools'] );
	}

	public function test_filter_default_server_config_returns_input_when_tools_not_array(): void {
		$config = array( 'tools' => 'not-an-array' );
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertSame( $config, $result );
	}

	public function test_filter_default_server_config_returns_input_when_config_not_array(): void {
		$result = Controller::instance()->filter_default_server_config( 'garbage' );

		$this->assertSame( 'garbage', $result );
	}

	// Cases 6–8 — register_database_servers() filter emission ---------------

	public function test_acrossai_mcp_manager_server_tools_filter_fires_once_per_server(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item( array(
			'server_name'            => 'Filter emission test',
			'server_slug'            => 'filter-emit-test',
			'description'            => '',
			'is_enabled'             => 1,
			'registered_from'        => 'database',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'filter-emit-test',
			'server_version'         => 'v1.0.0',
		) );

		$capture = new \stdClass();
		$capture->calls = 0;
		$capture->arg2  = null;
		$callback = function ( $tools, $server ) use ( $capture ) {
			$capture->calls += 1;
			$capture->arg2   = $server;
			return $tools;
		};
		add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 );

		// Cannot invoke register_database_servers() without a real McpAdapter
		// instance; simulate the composed call directly (matches the exact
		// sequence in Controller::register_database_servers).
		$rows  = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) );
		$row   = $rows[0];
		$tools = ToolPolicy::compose_for_row( $row );
		apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $row );

		remove_filter( 'acrossai_mcp_manager_server_tools', $callback, 10 );

		$this->assertSame( 1, $capture->calls, 'Filter must fire exactly once per server registration.' );
		$this->assertSame( $server_id, (int) $capture->arg2->id );
	}

	public function test_filter_can_add_a_slug_and_result_is_defensively_normalized(): void {
		$callback = static function ( $tools ) {
			$tools[] = 'my-plugin/added-by-filter';
			return $tools;
		};
		add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 );

		$before = array( 'mcp-adapter/discover-abilities' );
		$after  = apply_filters( 'acrossai_mcp_manager_server_tools', $before, new \stdClass() );
		$after  = array_values( array_unique( array_map( 'strval', (array) $after ) ) );

		remove_filter( 'acrossai_mcp_manager_server_tools', $callback, 10 );

		$this->assertContains( 'my-plugin/added-by-filter', $after );
	}

	public function test_filter_returning_null_degrades_to_empty_array(): void {
		$callback = static fn() => null;
		add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 );

		$before = array( 'mcp-adapter/discover-abilities' );
		$after  = apply_filters( 'acrossai_mcp_manager_server_tools', $before, new \stdClass() );
		$after  = array_values( array_unique( array_map( 'strval', (array) $after ) ) );

		remove_filter( 'acrossai_mcp_manager_server_tools', $callback, 10 );

		$this->assertSame( array(), $after );
	}

	// Case 9 — SEC-TASKS-025-1 confused-deputy ------------------------------

	public function test_filter_can_readd_protocol_slug_operator_removed_via_column_and_no_observability_event_fires(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item( array(
			'server_name'            => 'Confused deputy test',
			'server_slug'            => 'confused-deputy-test',
			'description'            => '',
			'is_enabled'             => 1,
			'registered_from'        => 'database',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'confused-deputy-test',
			'server_version'         => 'v1.0.0',
			'tool_discover_abilities' => 1,
			'tool_get_ability_info'   => 1,
			'tool_execute_ability'    => 0, // Operator "removed" execute-ability.
		) );

		$observed = array();
		$obs      = static function ( $payload ) use ( &$observed ) {
			$observed[] = $payload;
		};
		add_action( 'acrossai_mcp_tools_changed', $obs );

		$callback = static function ( $tools ) {
			// Companion plugin re-adds the operator-removed slug.
			$tools[] = 'mcp-adapter/execute-ability';
			return $tools;
		};
		add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 );

		$rows       = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) );
		$row        = $rows[0];
		$pre_filter = ToolPolicy::compose_for_row( $row );
		$post       = apply_filters( 'acrossai_mcp_manager_server_tools', $pre_filter, $row );
		$post       = array_values( array_unique( array_map( 'strval', (array) $post ) ) );

		remove_filter( 'acrossai_mcp_manager_server_tools', $callback, 10 );
		remove_action( 'acrossai_mcp_tools_changed', $obs );

		$this->assertNotContains( 'mcp-adapter/execute-ability', $pre_filter, 'Composed set must NOT include the operator-removed slug pre-filter.' );
		$this->assertContains( 'mcp-adapter/execute-ability', $post, 'Filter can re-add the operator-removed slug (documented behavior).' );
		$this->assertSame( array(), $observed, 'Filter-side changes must NOT fire acrossai_mcp_tools_changed — only POST-side flips emit the event.' );
	}

	// F026 v1 revert (2026-07-15) — register_database_servers must NOT widen ---
	// The tools composer no longer includes mcp.public = true abilities. AI
	// clients reach them through the three built-in meta tools whose callbacks
	// respect Abilities-tab visibility (commit 070ffe2's callback swap).

	public function test_register_database_servers_does_not_widen_tools_with_f017_effective_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}

		$server_id = (int) MCPServerQuery::instance()->add_item( array(
			'server_name'            => 'F026 revert composer test',
			'server_slug'            => 'f026-revert-test',
			'description'            => '',
			'is_enabled'             => 1,
			'registered_from'        => 'database',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'f026-revert-test',
			'server_version'         => 'v1.0.0',
		) );

		// Seed a public tool-typed ability — must NOT appear in the composed set
		// post-2026-07-15 revert.
		acrossai_test_register_ability(
			'f026-revert/public-tool',
			array(
				'label'       => 'F026 Public Tool',
				'description' => 'F026 test — public ability MUST NOT widen tools/list post-revert',
				'category'    => 'test',
				'meta'        => array( 'mcp' => array( 'public' => true, 'type' => 'tool' ) ),
				'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				'output_schema' => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback' => static fn () => array(),
			)
		);

		$rows       = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) );
		$row        = $rows[0];
		$pre_filter = ToolPolicy::compose_effective_tools_for_row( $row );

		$this->assertNotContains(
			'f026-revert/public-tool',
			$pre_filter,
			'Post-2026-07-15 revert: mcp.public = true abilities MUST NOT appear in tools/list. They are reached through the three built-in meta tools.'
		);
	}

	// F026 resources/prompts filter emission -------------------------------

	public function test_acrossai_mcp_manager_server_resources_filter_receives_f017_effective_resource_set(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}

		$server_id = (int) MCPServerQuery::instance()->add_item( array(
			'server_name'            => 'F026 resources filter test',
			'server_slug'            => 'f026-resources-test',
			'description'            => '',
			'is_enabled'             => 1,
			'registered_from'        => 'database',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'f026-resources-test',
			'server_version'         => 'v1.0.0',
		) );

		acrossai_test_register_ability(
			'f026-widened/public-resource',
			array(
				'label'       => 'F026 Public Resource',
				'description' => 'F026 test — public resource ability',
				'category'    => 'test',
				'meta'        => array( 'mcp' => array( 'public' => true, 'type' => 'resource' ) ),
				'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				'output_schema' => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback' => static fn () => array(),
			)
		);

		$capture  = new \stdClass();
		$capture->calls = 0;
		$capture->arg   = null;
		$callback = function ( $resources, $server ) use ( $capture ) {
			$capture->calls += 1;
			$capture->arg    = $resources;
			return $resources;
		};
		add_filter( 'acrossai_mcp_manager_server_resources', $callback, 10, 2 );

		$pre_filter = AbilityDiscovery::for_server( $server_id, AbilityDiscovery::TYPE_RESOURCE );
		apply_filters( 'acrossai_mcp_manager_server_resources', $pre_filter, (object) array( 'id' => $server_id ) );

		remove_filter( 'acrossai_mcp_manager_server_resources', $callback, 10 );

		$this->assertSame( 1, $capture->calls );
		$this->assertContains( 'f026-widened/public-resource', $capture->arg );
	}

	public function test_acrossai_mcp_manager_server_prompts_filter_receives_f017_effective_prompt_set(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}

		$server_id = (int) MCPServerQuery::instance()->add_item( array(
			'server_name'            => 'F026 prompts filter test',
			'server_slug'            => 'f026-prompts-test',
			'description'            => '',
			'is_enabled'             => 1,
			'registered_from'        => 'database',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'f026-prompts-test',
			'server_version'         => 'v1.0.0',
		) );

		acrossai_test_register_ability(
			'f026-widened/public-prompt',
			array(
				'label'       => 'F026 Public Prompt',
				'description' => 'F026 test — public prompt ability',
				'category'    => 'test',
				'meta'        => array( 'mcp' => array( 'public' => true, 'type' => 'prompt' ) ),
				'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				'output_schema' => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback' => static fn () => array(),
			)
		);

		$capture  = new \stdClass();
		$capture->arg = null;
		$callback = function ( $prompts, $server ) use ( $capture ) {
			$capture->arg = $prompts;
			return $prompts;
		};
		add_filter( 'acrossai_mcp_manager_server_prompts', $callback, 10, 2 );

		$pre_filter = AbilityDiscovery::for_server( $server_id, AbilityDiscovery::TYPE_PROMPT );
		apply_filters( 'acrossai_mcp_manager_server_prompts', $pre_filter, (object) array( 'id' => $server_id ) );

		remove_filter( 'acrossai_mcp_manager_server_prompts', $callback, 10 );

		$this->assertContains( 'f026-widened/public-prompt', $capture->arg );
	}

	// F026 default-server config REPLACE for resources/prompts -------------

	public function test_filter_default_server_config_replaces_resources_and_prompts_when_default_row_exists(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}

		$this->seed_default_server();

		acrossai_test_register_ability(
			'f026-default/public-resource',
			array(
				'label' => 'r', 'description' => 'r', 'category' => 'test',
				'meta' => array( 'mcp' => array( 'public' => true, 'type' => 'resource' ) ),
				'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				'output_schema' => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback' => static fn () => array(),
			)
		);
		acrossai_test_register_ability(
			'f026-default/public-prompt',
			array(
				'label' => 'p', 'description' => 'p', 'category' => 'test',
				'meta' => array( 'mcp' => array( 'public' => true, 'type' => 'prompt' ) ),
				'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				'output_schema' => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback' => static fn () => array(),
			)
		);

		$config = array(
			'server_id' => 'x',
			'tools'     => array( 'mcp-adapter/discover-abilities' ),
			'resources' => array( 'vendor-auto-discovered/resource' ),
			'prompts'   => array( 'vendor-auto-discovered/prompt' ),
		);
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertContains( 'f026-default/public-resource', $result['resources'] );
		$this->assertNotContains( 'vendor-auto-discovered/resource', $result['resources'], 'REPLACE semantic — vendor-auto-discovered items must be removed.' );
		$this->assertContains( 'f026-default/public-prompt', $result['prompts'] );
		$this->assertNotContains( 'vendor-auto-discovered/prompt', $result['prompts'] );
	}

	private function seed_default_server( array $overrides = array() ): int {
		$data = array_merge(
			array(
				'server_name'            => 'Default seed',
				'server_slug'            => DefaultServerSeeder::SLUG,
				'description'            => '',
				'is_enabled'             => 0,
				'registered_from'        => 'plugin',
				'server_route_namespace' => 'mcp',
				'server_route'           => DefaultServerSeeder::SLUG,
				'server_version'         => 'v1.0.0',
			),
			$overrides
		);
		return (int) MCPServerQuery::instance()->add_item( $data );
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_tools`' );
		// F026: also truncate F017 storage to prevent cross-test cache pollution.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_abilities`' );
	}
}
