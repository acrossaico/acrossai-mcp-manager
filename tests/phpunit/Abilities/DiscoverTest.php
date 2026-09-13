<?php
/**
 * Discover — unit coverage for the plugin-owned discover-abilities callback.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\Abilities\Discover;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use WP\MCP\Core\McpServer;
use WP_UnitTestCase;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class DiscoverTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		CurrentServerHolder::instance()->clear();
		ExposureResolver::_reset_cache_for_tests();
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		remove_all_filters( 'mcp_adapter_discover_abilities_capability' );
	}

	public function tearDown(): void {
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		remove_all_filters( 'mcp_adapter_discover_abilities_capability' );
		CurrentServerHolder::instance()->clear();
		$this->truncate_tables();
		// Best-effort restore of the row tests/bootstrap-wp.php seeded via
		// Activator::activate(). NOTE: with autocommit=0 this INSERT lands in
		// the transaction parent::tearDown() rolls back, so a test that needs
		// the managed rows must seed them itself (see
		// DefaultServerSeederTest::set_up()).
		DefaultServerSeeder::seed();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// execute()
	// -----------------------------------------------------------------

	public function test_execute_returns_public_abilities_by_default(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/public-a', true, 'tool' );

		$result = Discover::execute();
		$names  = array_column( $result['abilities'], 'name' );

		$this->assertContains( 'discover-test/public-a', $names );
	}

	public function test_execute_filter_narrows_public_ability(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/to-hide', true, 'tool' );

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability ) {
				return 'discover-test/to-hide' === $ability->get_name() ? false : $exposed;
			},
			10,
			4
		);

		$names = array_column( Discover::execute()['abilities'], 'name' );
		$this->assertNotContains( 'discover-test/to-hide', $names );
	}

	public function test_execute_filter_widens_private_ability(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/normally-hidden', false, 'tool' );

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability ) {
				return 'discover-test/normally-hidden' === $ability->get_name() ? true : $exposed;
			},
			10,
			4
		);

		$names = array_column( Discover::execute()['abilities'], 'name' );
		$this->assertContains( 'discover-test/normally-hidden', $names );
	}

	public function test_execute_filter_receives_server_id_when_holder_set(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/probe', true, 'tool' );

		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Probe',
				'server_slug'            => 'discover-probe-server',
				'description'            => '',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'discover-probe-server',
				'server_version'         => 'v1.0.0',
			)
		);
		CurrentServerHolder::instance()->set( $this->fake_server( 'discover-probe-server' ) );

		$captured = null;
		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability, $sid, $ctx ) use ( &$captured ) {
				if ( 'discover-test/probe' === $ability->get_name() ) {
					$captured = $sid;
				}
				return $exposed;
			},
			10,
			4
		);

		Discover::execute();
		$this->assertSame( $server_id, $captured );
	}

	public function test_execute_filter_receives_null_server_id_when_holder_empty(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/no-holder', true, 'tool' );

		$captured = 'unset';
		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability, $sid, $ctx ) use ( &$captured ) {
				if ( 'discover-test/no-holder' === $ability->get_name() ) {
					$captured = $sid;
				}
				return $exposed;
			},
			10,
			4
		);

		Discover::execute();
		$this->assertNull( $captured );
	}

	public function test_execute_filter_context_is_discover(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/ctx', true, 'tool' );

		$captured = null;
		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability, $sid, $ctx ) use ( &$captured ) {
				if ( 'discover-test/ctx' === $ability->get_name() ) {
					$captured = $ctx;
				}
				return $exposed;
			},
			10,
			4
		);

		Discover::execute();
		$this->assertSame( 'discover', $captured );
	}

	public function test_execute_skips_non_tool_types(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/a-resource', true, 'resource' );

		$names = array_column( Discover::execute()['abilities'], 'name' );
		$this->assertNotContains( 'discover-test/a-resource', $names );
	}

	// -----------------------------------------------------------------
	// check_permission()
	// -----------------------------------------------------------------

	public function test_check_permission_rejects_unauthenticated(): void {
		wp_set_current_user( 0 );
		$result = Discover::check_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authentication_required', $result->get_error_code() );
	}

	public function test_check_permission_rejects_missing_capability(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		add_filter( 'mcp_adapter_discover_abilities_capability', static fn () => 'manage_options' );

		$result = Discover::check_permission();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	public function test_check_permission_allows_authenticated_read_capable_user(): void {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( Discover::check_permission() );
	}

	// -----------------------------------------------------------------
	// F017 per-server override integration (regression for the bug
	// where apply_exposure_filter's default was only meta.mcp.public,
	// ignoring is_exposed=1 rows).
	// -----------------------------------------------------------------

	public function test_execute_includes_non_public_ability_with_f017_override_when_holder_set(): void {
		$this->maybe_skip_abilities_api();

		$server_id = $this->seed_server( 'discover-f017-server-1' );
		CurrentServerHolder::instance()->set( $this->fake_server( 'discover-f017-server-1' ) );

		// Ability is NOT statically public — the plugin's Abilities-tab UX
		// says it should still be exposed for this server via the F017 row.
		$this->register_scratch_ability( 'discover-test/f017-widened', false, 'tool' );
		MCPServerAbilityQuery::instance()->upsert( $server_id, 'discover-test/f017-widened', true );
		ExposureResolver::_reset_cache_for_tests(); // Cache miss after upsert.

		$names = array_column( Discover::execute()['abilities'], 'name' );
		$this->assertContains(
			'discover-test/f017-widened',
			$names,
			'apply_exposure_filter default must consult ExposureResolver::resolve, not just meta.mcp.public — is_exposed=1 override should include the ability.'
		);
	}

	public function test_execute_excludes_public_ability_with_f017_override_disabled_when_holder_set(): void {
		$this->maybe_skip_abilities_api();

		$server_id = $this->seed_server( 'discover-f017-server-2' );
		CurrentServerHolder::instance()->set( $this->fake_server( 'discover-f017-server-2' ) );

		// Ability IS statically public — but the operator hid it for this
		// server via the Abilities tab (is_exposed=0). Should be excluded.
		$this->register_scratch_ability( 'discover-test/f017-narrowed', true, 'tool' );
		MCPServerAbilityQuery::instance()->upsert( $server_id, 'discover-test/f017-narrowed', false );
		ExposureResolver::_reset_cache_for_tests();

		$names = array_column( Discover::execute()['abilities'], 'name' );
		$this->assertNotContains(
			'discover-test/f017-narrowed',
			$names,
			'is_exposed=0 override must hide even a normally-public ability.'
		);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private function seed_server( string $slug ): int {
		return (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'DiscoverTest F017',
				'server_slug'            => $slug,
				'description'            => '',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
	}

	private function maybe_skip_abilities_api(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
	}

	// -----------------------------------------------------------------
	// F089 — search + pagination
	// -----------------------------------------------------------------

	public function test_zero_argument_call_still_returns_every_visible_ability(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/back-compat', true, 'tool' );

		$result = Discover::execute();

		$this->assertContains( 'discover-test/back-compat', array_column( $result['abilities'], 'name' ) );
		$this->assertSame( count( $result['abilities'] ), $result['returned'] );
		$this->assertSame( 1, $result['page'] );
	}

	public function test_each_entry_carries_its_category(): void {
		$this->maybe_skip_abilities_api();
		$this->register_rich_ability( 'discover-cat/one', 'One', 'desc', 'reporting' );

		$entry = $this->entry_for( Discover::execute(), 'discover-cat/one' );

		$this->assertSame( 'reporting', $entry['category'] );
	}

	public function test_default_page_size_is_60_and_flags_more(): void {
		$this->maybe_skip_abilities_api();
		for ( $i = 0; $i < 61; $i++ ) {
			$this->register_rich_ability( sprintf( 'discover-page/a-%02d', $i ), 'Paged', 'desc', 'paging' );
		}

		$first = Discover::execute( array( 'namespace' => 'discover-page' ) );

		$this->assertSame( 61, $first['total'], 'total must count matches BEFORE the slice.' );
		$this->assertSame( Discover::PER_PAGE_DEFAULT, $first['per_page'] );
		$this->assertCount( 60, $first['abilities'] );
		$this->assertSame( 60, $first['returned'] );
		$this->assertTrue( $first['has_more'] );

		$second = Discover::execute( array( 'namespace' => 'discover-page', 'page' => 2 ) );

		$this->assertSame( 61, $second['total'] );
		$this->assertCount( 1, $second['abilities'] );
		$this->assertFalse( $second['has_more'], 'The final page must not claim more.' );
		$this->assertNotSame(
			array_column( $first['abilities'], 'name' ),
			array_column( $second['abilities'], 'name' ),
			'Page 2 must be a different window.'
		);
	}

	public function test_page_past_the_end_is_empty_without_has_more(): void {
		$this->maybe_skip_abilities_api();
		$this->register_rich_ability( 'discover-end/only', 'Only', 'desc', 'ending' );

		$result = Discover::execute( array( 'namespace' => 'discover-end', 'page' => 9 ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( array(), $result['abilities'] );
		$this->assertSame( 0, $result['returned'] );
		$this->assertFalse( $result['has_more'] );
	}

	public function test_search_matches_each_text_field_case_insensitively(): void {
		$this->maybe_skip_abilities_api();
		$this->register_rich_ability( 'discover-find/alpha', 'Zebra Label', 'Ordinary text', 'finding' );

		foreach ( array( 'ALPHA', 'zebra', 'ORDINARY', 'Finding' ) as $needle ) {
			$names = array_column(
				Discover::execute( array( 'search' => $needle ) )['abilities'],
				'name'
			);
			$this->assertContains( 'discover-find/alpha', $names, "search '{$needle}' must match." );
		}

		$miss = Discover::execute( array( 'search' => 'no-such-substring-anywhere' ) );
		$this->assertSame( 0, $miss['total'] );
	}

	public function test_category_is_exact_and_namespace_is_a_prefix(): void {
		$this->maybe_skip_abilities_api();
		$this->register_rich_ability( 'discover-scope/in', 'In', 'desc', 'scoped' );
		$this->register_rich_ability( 'discover-other/out', 'Out', 'desc', 'scoped-extra' );

		$by_category = Discover::execute( array( 'category' => 'scoped' ) );
		$this->assertSame( array( 'discover-scope/in' ), array_column( $by_category['abilities'], 'name' ) );

		$by_namespace = Discover::execute( array( 'namespace' => 'discover-scope' ) );
		$this->assertSame( array( 'discover-scope/in' ), array_column( $by_namespace['abilities'], 'name' ) );

		// A namespace must not match by bare prefix across the slash boundary.
		$partial = Discover::execute( array( 'namespace' => 'discover-sco' ) );
		$this->assertSame( 0, $partial['total'] );
	}

	public function test_criteria_and_together(): void {
		$this->maybe_skip_abilities_api();
		$this->register_rich_ability( 'discover-and/keep', 'Keep', 'desc', 'combined' );
		$this->register_rich_ability( 'discover-and/drop', 'Drop', 'desc', 'other' );

		$result = Discover::execute(
			array(
				'namespace' => 'discover-and',
				'category'  => 'combined',
				'search'    => 'keep',
			)
		);

		$this->assertSame( array( 'discover-and/keep' ), array_column( $result['abilities'], 'name' ) );
	}

	/**
	 * The security invariant: caller-supplied filtering runs AFTER the exposure
	 * gate, so no search term can surface an ability the per-server policy hides.
	 */
	public function test_hidden_ability_is_unreachable_through_any_criterion(): void {
		$this->maybe_skip_abilities_api();
		$this->register_rich_ability( 'discover-hidden/secret', 'Secret', 'desc', 'classified' );

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability_name ) {
				return 'discover-hidden/secret' === $ability_name ? false : $exposed;
			},
			10,
			2
		);
		ExposureResolver::_reset_cache_for_tests();

		foreach (
			array(
				array( 'search' => 'secret' ),
				array( 'search' => 'classified' ),
				array( 'category' => 'classified' ),
				array( 'namespace' => 'discover-hidden' ),
				array(),
			) as $criteria
		) {
			$names = array_column( Discover::execute( $criteria )['abilities'], 'name' );
			$this->assertNotContains(
				'discover-hidden/secret',
				$names,
				'Exposure gate must win over ' . wp_json_encode( $criteria )
			);
		}
	}

	public function test_per_page_is_clamped_to_the_supported_range(): void {
		$this->maybe_skip_abilities_api();
		$this->register_rich_ability( 'discover-clamp/one', 'One', 'desc', 'clamping' );

		$too_big = Discover::execute( array( 'namespace' => 'discover-clamp', 'per_page' => 9999 ) );
		$this->assertSame( Discover::PER_PAGE_MAXIMUM, $too_big['per_page'] );

		$too_small = Discover::execute( array( 'namespace' => 'discover-clamp', 'per_page' => 0 ) );
		$this->assertSame( 1, $too_small['per_page'] );

		$negative_page = Discover::execute( array( 'namespace' => 'discover-clamp', 'page' => -5 ) );
		$this->assertSame( 1, $negative_page['page'] );
	}

	public function test_filters_override_the_default_and_maximum_page_size(): void {
		$this->maybe_skip_abilities_api();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->register_rich_ability( sprintf( 'discover-filter/a-%d', $i ), 'Filtered', 'desc', 'filtering' );
		}

		add_filter( 'acrossai_mcp_discover_abilities_default_per_page', static fn () => 2 );
		$defaulted = Discover::execute( array( 'namespace' => 'discover-filter' ) );
		$this->assertSame( 2, $defaulted['per_page'] );
		$this->assertTrue( $defaulted['has_more'] );

		add_filter( 'acrossai_mcp_discover_abilities_max_per_page', static fn () => 1 );
		$capped = Discover::execute( array( 'namespace' => 'discover-filter', 'per_page' => 50 ) );
		$this->assertSame( 1, $capped['per_page'], 'The max filter must clamp an explicit per_page.' );

		remove_all_filters( 'acrossai_mcp_discover_abilities_default_per_page' );
		remove_all_filters( 'acrossai_mcp_discover_abilities_max_per_page' );
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, string>
	 */
	private function entry_for( array $result, string $name ): array {
		foreach ( $result['abilities'] as $entry ) {
			if ( $entry['name'] === $name ) {
				return $entry;
			}
		}

		$this->fail( "Ability {$name} was not returned." );
	}

	private function register_rich_ability( string $slug, string $label, string $description, string $category ): void {
		acrossai_test_register_ability(
			$slug,
			array(
				'label'            => $label,
				'description'      => $description,
				'category'         => $category,
				'meta'             => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
				'input_schema'     => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'    => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback' => static fn () => array(),
			)
		);
	}

	private function register_scratch_ability( string $slug, bool $mcp_public, string $type ): void {
		acrossai_test_register_ability(
			$slug,
			array(
				'label'            => ucfirst( basename( $slug ) ),
				'description'      => 'Discover test scratch',
				'category'         => 'test',
				'meta'             => array(
					'mcp' => array(
						'public' => $mcp_public,
						'type'   => $type,
					),
				),
				'input_schema'     => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'    => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback' => static fn () => array(),
			)
		);
	}

	private function fake_server( string $slug ): McpServer {
		$mock = $this->createMock( McpServer::class );
		$mock->method( 'get_server_id' )->willReturn( $slug );
		return $mock;
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_abilities`' );
	}
}
