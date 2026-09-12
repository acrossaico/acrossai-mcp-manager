<?php
/**
 * GetAbilityInfo — unit coverage for the plugin-owned get-ability-info callback.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\Abilities\GetAbilityInfo;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class GetAbilityInfoTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		CurrentServerHolder::instance()->clear();
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		remove_all_filters( 'mcp_adapter_get_ability_info_capability' );
	}

	public function tearDown(): void {
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		remove_all_filters( 'mcp_adapter_get_ability_info_capability' );
		CurrentServerHolder::instance()->clear();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// check_permission()
	// -----------------------------------------------------------------

	public function test_check_permission_rejects_missing_ability_name(): void {
		$this->login_admin();
		$result = GetAbilityInfo::check_permission( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_ability_name', $result->get_error_code() );
	}

	public function test_check_permission_rejects_unauthenticated(): void {
		wp_set_current_user( 0 );
		$result = GetAbilityInfo::check_permission( array( 'ability_name' => 'x/y' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authentication_required', $result->get_error_code() );
	}

	public function test_check_permission_rejects_missing_capability(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		add_filter( 'mcp_adapter_get_ability_info_capability', static fn () => 'manage_options' );

		$result = GetAbilityInfo::check_permission( array( 'ability_name' => 'x/y' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	public function test_check_permission_rejects_missing_ability(): void {
		$this->maybe_skip_abilities_api();
		$this->login_admin();

		$result = GetAbilityInfo::check_permission( array( 'ability_name' => 'nowhere/nothing' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_not_found', $result->get_error_code() );
	}

	public function test_check_permission_rejects_ability_hidden_by_filter(): void {
		$this->maybe_skip_abilities_api();
		$this->login_admin();
		$this->register_scratch_ability( 'get-info-test/hidden', true, 'tool' );

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability ) {
				return 'get-info-test/hidden' === $ability->get_name() ? false : $exposed;
			},
			10,
			4
		);

		$result = GetAbilityInfo::check_permission( array( 'ability_name' => 'get-info-test/hidden' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'acrossai_mcp_ability_not_exposed_for_server', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_check_permission_allows_ability_widened_by_filter(): void {
		$this->maybe_skip_abilities_api();
		$this->login_admin();
		$this->register_scratch_ability( 'get-info-test/normally-hidden', false, 'tool' );

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability ) {
				return 'get-info-test/normally-hidden' === $ability->get_name() ? true : $exposed;
			},
			10,
			4
		);

		$result = GetAbilityInfo::check_permission( array( 'ability_name' => 'get-info-test/normally-hidden' ) );
		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------
	// execute()
	// -----------------------------------------------------------------

	public function test_execute_returns_full_info_including_meta(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'get-info-test/full', true, 'tool' );

		$info = GetAbilityInfo::execute( array( 'ability_name' => 'get-info-test/full' ) );

		$this->assertSame( 'get-info-test/full', $info['name'] );
		$this->assertArrayHasKey( 'label', $info );
		$this->assertArrayHasKey( 'description', $info );
		$this->assertArrayHasKey( 'input_schema', $info );
		$this->assertArrayHasKey( 'meta', $info );
	}

	public function test_execute_returns_error_shape_on_missing_ability_name(): void {
		$this->maybe_skip_abilities_api();
		$result = GetAbilityInfo::execute( array() );
		$this->assertSame( array( 'error' => 'Ability name is required' ), $result );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private function login_admin(): void {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
	}

	private function maybe_skip_abilities_api(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
	}

	private function register_scratch_ability( string $slug, bool $mcp_public, string $type ): void {
		acrossai_test_register_ability(
			$slug,
			array(
				'label'            => ucfirst( basename( $slug ) ),
				'description'      => 'GetAbilityInfo test scratch',
				'category'         => 'test',
				'meta'             => array(
					'mcp' => array(
						'public' => $mcp_public,
						'type'   => $type,
					),
				),
				'input_schema'     => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'    => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'execute_callback' => static fn () => array(),
			)
		);
	}
}
