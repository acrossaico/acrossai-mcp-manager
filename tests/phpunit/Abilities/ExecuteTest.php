<?php
/**
 * Execute — unit coverage for the plugin-owned execute-ability callback.
 *
 * Includes the critical "exposure ≠ authorization" invariant test — a
 * companion filter callback widening exposure MUST NOT bypass the target
 * ability's own permission_callback.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\Abilities\Execute;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ExecuteTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		CurrentServerHolder::instance()->clear();
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		remove_all_filters( 'mcp_adapter_execute_ability_capability' );
	}

	public function tearDown(): void {
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		remove_all_filters( 'mcp_adapter_execute_ability_capability' );
		CurrentServerHolder::instance()->clear();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// check_permission()
	// -----------------------------------------------------------------

	public function test_check_permission_rejects_missing_ability_name(): void {
		$this->login_admin();
		$result = Execute::check_permission( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_ability_name', $result->get_error_code() );
	}

	public function test_check_permission_rejects_unauthenticated(): void {
		wp_set_current_user( 0 );
		$result = Execute::check_permission( array( 'ability_name' => 'x/y' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authentication_required', $result->get_error_code() );
	}

	public function test_check_permission_rejects_missing_capability(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		add_filter( 'mcp_adapter_execute_ability_capability', static fn () => 'manage_options' );

		$result = Execute::check_permission( array( 'ability_name' => 'x/y' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	public function test_check_permission_rejects_missing_ability(): void {
		$this->maybe_skip_abilities_api();
		$this->login_admin();
		$result = Execute::check_permission( array( 'ability_name' => 'nowhere/nothing' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_not_found', $result->get_error_code() );
	}

	public function test_check_permission_rejects_ability_hidden_by_filter(): void {
		$this->maybe_skip_abilities_api();
		$this->login_admin();
		$this->register_scratch_ability( 'execute-test/hidden', true, 'tool', '__return_true' );

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability ) {
				return 'execute-test/hidden' === $ability->get_name() ? false : $exposed;
			},
			10,
			4
		);

		$result = Execute::check_permission( array( 'ability_name' => 'execute-test/hidden', 'parameters' => new \stdClass() ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'acrossai_mcp_ability_not_exposed_for_server', $result->get_error_code() );
	}

	public function test_check_permission_allows_exposed_ability_with_passing_target_permission(): void {
		$this->maybe_skip_abilities_api();
		$this->login_admin();
		$this->register_scratch_ability( 'execute-test/allowed', true, 'tool', '__return_true' );

		$result = Execute::check_permission( array( 'ability_name' => 'execute-test/allowed', 'parameters' => new \stdClass() ) );
		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------
	// Critical security-invariant: exposure ≠ authorization.
	// If a companion filter widens exposure to include a non-public ability
	// whose own permission_callback denies, our check_permission MUST return
	// the target's WP_Error, not silently allow.
	// -----------------------------------------------------------------

	public function test_filter_widening_does_not_bypass_target_permission_callback(): void {
		$this->maybe_skip_abilities_api();
		$this->login_admin();

		$this->register_scratch_ability(
			'execute-test/exposed-but-denied',
			false, // Not statically public — filter will widen.
			'tool',
			static function () {
				return new \WP_Error( 'target_denied', 'Nope.' );
			}
		);

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability ) {
				return 'execute-test/exposed-but-denied' === $ability->get_name() ? true : $exposed;
			},
			10,
			4
		);

		$result = Execute::check_permission( array( 'ability_name' => 'execute-test/exposed-but-denied', 'parameters' => new \stdClass() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'target_denied',
			$result->get_error_code(),
			"Failure must come from the target ability's permission_callback, not the exposure gate."
		);
	}

	// -----------------------------------------------------------------
	// execute()
	// -----------------------------------------------------------------

	public function test_execute_returns_error_shape_on_missing_ability_name(): void {
		$result = Execute::execute( array() );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Ability name is required', $result['error'] );
	}

	public function test_execute_returns_error_shape_on_missing_ability(): void {
		$this->maybe_skip_abilities_api();
		$result = Execute::execute( array( 'ability_name' => 'nowhere/nothing', 'parameters' => new \stdClass() ) );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_execute_returns_success_shape_on_ok(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability(
			'execute-test/ok',
			true,
			'tool',
			'__return_true',
			static function () {
				return array( 'result' => 'yes' );
			}
		);

		$result = Execute::execute( array( 'ability_name' => 'execute-test/ok', 'parameters' => new \stdClass() ) );
		$this->assertTrue( $result['success'], (string) ( $result['error'] ?? 'no error reported' ) );
		$this->assertSame( array( 'result' => 'yes' ), $result['data'] );
	}

	public function test_execute_catches_thrown_exceptions(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability(
			'execute-test/throws',
			true,
			'tool',
			'__return_true',
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		$result = Execute::execute( array( 'ability_name' => 'execute-test/throws', 'parameters' => new \stdClass() ) );
		$this->assertFalse( $result['success'] );
		// WP 6.9 wraps a throwing execute_callback's message as
		// 'Ability "x/y" callback threw an exception: boom'. Assert the
		// cause is surfaced rather than pinning core's exact wording.
		$this->assertStringContainsString( 'boom', $result['error'] );
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

	/**
	 * @param string   $slug              Ability slug.
	 * @param bool     $mcp_public        Value for meta.mcp.public.
	 * @param string   $type              Value for meta.mcp.type.
	 * @param callable $permission_cb     Permission callback.
	 * @param callable $execute_cb        Optional execute callback (defaults to no-op returning empty array).
	 */
	private function register_scratch_ability( string $slug, bool $mcp_public, string $type, $permission_cb, $execute_cb = null ): void {
		acrossai_test_register_ability(
			$slug,
			array(
				'label'               => ucfirst( basename( $slug ) ),
				'description'         => 'Execute test scratch',
				'category'            => 'test',
				'meta'                => array(
					'mcp' => array(
						'public' => $mcp_public,
						'type'   => $type,
					),
				),
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'permission_callback' => $permission_cb,
				'execute_callback'    => $execute_cb ?? static fn () => array(),
			)
		);
	}
}
