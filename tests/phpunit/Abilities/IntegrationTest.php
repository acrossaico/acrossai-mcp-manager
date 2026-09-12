<?php
/**
 * Abilities — integration smoke.
 *
 * Verifies the end-to-end callback-swap: register `wp_register_ability_args`
 * hook, register a "vendor-shaped" ability with the same slug as one of the
 * three defaults, and confirm the plugin-owned callbacks are the ones bound
 * to the resulting `WP_Ability` instance.
 *
 * NOT a full ToolsHandler dispatch test — that harness requires substantial
 * vendor fixture setup (see plan §4.6 note about upstream PR #244 CI battle).
 * A dispatch-level integration test is deferred to end-to-end smoke via curl
 * against a live wp-env (documented in the plan's verification checklist).
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CallbackReplacer;
use AcrossAI_MCP_Manager\Includes\Abilities\Discover;
use AcrossAI_MCP_Manager\Includes\Abilities\Execute;
use AcrossAI_MCP_Manager\Includes\Abilities\GetAbilityInfo;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class IntegrationTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Wire the CallbackReplacer as Main.php does at runtime.
		add_filter( 'wp_register_ability_args', array( CallbackReplacer::instance(), 'replace_callbacks' ), 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_register_ability_args', array( CallbackReplacer::instance(), 'replace_callbacks' ), 10 );
		parent::tearDown();
	}

	public function test_registering_vendor_slug_binds_our_discover_callbacks(): void {
		$this->maybe_skip_abilities_api();
		$this->register_stub_vendor_ability( 'mcp-adapter/discover-abilities' );

		$ability = \wp_get_ability( 'mcp-adapter/discover-abilities' );
		$this->assertNotNull( $ability );

		// Vendor's ability calls `execute_callback` via WP_Ability::execute().
		// We can't invoke that here without auth setup, but we CAN verify the
		// bindings via reflection.
		$reflection = new \ReflectionObject( $ability );
		$this->assert_callback_bound( $reflection, $ability, 'execute_callback', array( Discover::class, 'execute' ) );
		$this->assert_callback_bound( $reflection, $ability, 'permission_callback', array( Discover::class, 'check_permission' ) );
	}

	public function test_registering_vendor_slug_binds_our_get_info_callbacks(): void {
		$this->maybe_skip_abilities_api();
		$this->register_stub_vendor_ability( 'mcp-adapter/get-ability-info' );

		$ability    = \wp_get_ability( 'mcp-adapter/get-ability-info' );
		$reflection = new \ReflectionObject( $ability );
		$this->assert_callback_bound( $reflection, $ability, 'execute_callback', array( GetAbilityInfo::class, 'execute' ) );
		$this->assert_callback_bound( $reflection, $ability, 'permission_callback', array( GetAbilityInfo::class, 'check_permission' ) );
	}

	public function test_registering_vendor_slug_binds_our_execute_callbacks(): void {
		$this->maybe_skip_abilities_api();
		$this->register_stub_vendor_ability( 'mcp-adapter/execute-ability' );

		$ability    = \wp_get_ability( 'mcp-adapter/execute-ability' );
		$reflection = new \ReflectionObject( $ability );
		$this->assert_callback_bound( $reflection, $ability, 'execute_callback', array( Execute::class, 'execute' ) );
		$this->assert_callback_bound( $reflection, $ability, 'permission_callback', array( Execute::class, 'check_permission' ) );
	}

	public function test_registering_non_vendor_slug_keeps_original_callbacks(): void {
		$this->maybe_skip_abilities_api();

		acrossai_test_register_ability(
			'integration-test/mine',
			array(
				'label'               => 'Mine',
				'description'         => 'Not one of the vendor three.',
				'category'            => 'test',
				'meta'                => array( 'mcp' => array( 'public' => true, 'type' => 'tool' ) ),
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_empty_array',
			)
		);

		$ability = \wp_get_ability( 'integration-test/mine' );
		$this->assertNotNull( $ability );

		$reflection = new \ReflectionObject( $ability );
		$this->assert_callback_bound( $reflection, $ability, 'execute_callback', '__return_empty_array' );
		$this->assert_callback_bound( $reflection, $ability, 'permission_callback', '__return_true' );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private function maybe_skip_abilities_api(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
	}

	/**
	 * Register an ability with the same slug as one of the vendor three,
	 * with vendor-shaped args. The CallbackReplacer must swap the callbacks
	 * via the `wp_register_ability_args` filter at registration time.
	 *
	 * @param string $slug Vendor ability slug to register.
	 */
	private function register_stub_vendor_ability( string $slug ): void {
		acrossai_test_register_ability(
			$slug,
			array(
				'label'               => 'Stub',
				'description'         => 'Stub for integration test',
				'category'            => 'mcp-adapter',
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_empty_array',
			)
		);
	}

	/**
	 * Assert that a WP_Ability's internal callback property is `$expected`.
	 * WP_Ability stores registration args as protected/private properties;
	 * reflection is the cleanest way to introspect without invoking.
	 *
	 * @param \ReflectionObject $reflection Reflection wrapping the ability.
	 * @param object            $ability    The WP_Ability instance.
	 * @param string            $property   The callback property name.
	 * @param mixed             $expected   Expected callable value.
	 */
	private function assert_callback_bound( \ReflectionObject $reflection, $ability, string $property, $expected ): void {
		if ( ! $reflection->hasProperty( $property ) ) {
			$this->markTestSkipped( "WP_Ability does not expose `{$property}` property — introspection strategy needs revisiting." );
		}
		$prop = $reflection->getProperty( $property );
		$prop->setAccessible( true );
		$actual = $prop->getValue( $ability );

		// F030's PermissionOverrideProcessor wraps EVERY ability's
		// permission_callback in a closure (via wp_register_ability_args), so a
		// raw identity check has been wrong since that feature shipped — it
		// just never ran. Unwrap to the closure's captured $original and assert
		// against that, which still proves OUR callback is the one bound.
		if ( 'permission_callback' === $property && $actual instanceof \Closure ) {
			$statics = ( new \ReflectionFunction( $actual ) )->getStaticVariables();
			$this->assertArrayHasKey(
				'original',
				$statics,
				'F030 wrapper must capture the original permission_callback as $original.'
			);
			$actual = $statics['original'];
		}

		$this->assertSame( $expected, $actual );
	}
}
