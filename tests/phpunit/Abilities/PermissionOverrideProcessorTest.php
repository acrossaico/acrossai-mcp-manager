<?php
/**
 * Feature 030 — PermissionOverrideProcessor unit coverage.
 *
 * Four fall-through scenarios for the wrapped closure:
 *   1. Null server context   → invoke original callback and return its result
 *   2. Override off          → invoke original callback and return its result
 *   3. Not exposed to server → invoke original callback and return its result
 *   4. All layers hold       → return `true` unconditionally
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\Abilities\PermissionOverrideProcessor;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use WP\MCP\Core\McpServer;
use WP_Error;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class PermissionOverrideProcessorTest extends WP_UnitTestCase {

	private int $server_id;

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		CurrentServerHolder::instance()->clear();
		PermissionOverrideProcessor::_reset_cache_for_tests();
		ExposureResolver::_reset_cache_for_tests();

		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'                   => 'F030 Test Server',
				'server_slug'                   => 'f030-test',
				'description'                   => '',
				'is_enabled'                    => 1,
				'registered_from'               => 'database',
				'server_route_namespace'        => 'mcp',
				'server_route'                  => 'f030-test',
				'server_version'                => 'v1.0.0',
				'override_abilities_permission' => 0,
			)
		);
	}

	public function tearDown(): void {
		CurrentServerHolder::instance()->clear();
		PermissionOverrideProcessor::_reset_cache_for_tests();
		ExposureResolver::_reset_cache_for_tests();
		$this->truncate_tables();
		parent::tearDown();
	}

	public function test_null_server_context_falls_through_to_original_callback(): void {
		// CurrentServerHolder is empty — closure must call the original.
		$original_calls = 0;
		$args           = array(
			'permission_callback' => function () use ( &$original_calls ): bool {
				++$original_calls;
				return true;
			},
		);

		$wrapped = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );

		$result = call_user_func( $wrapped['permission_callback'] );
		$this->assertTrue( $result, 'Original callback returned true; closure must propagate it.' );
		$this->assertSame( 1, $original_calls, 'Original callback must be invoked when server context is null.' );

		// Now assert deny propagates too.
		$original_calls = 0;
		$args           = array(
			'permission_callback' => function () use ( &$original_calls ): bool {
				++$original_calls;
				return false;
			},
		);
		$wrapped        = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );
		$this->assertFalse( call_user_func( $wrapped['permission_callback'] ) );
		$this->assertSame( 1, $original_calls );
	}

	public function test_override_off_falls_through_to_original_callback(): void {
		// Override flag is 0 by setUp; set the server context so we're not in
		// the null-context branch.
		CurrentServerHolder::instance()->set( $this->fake_server( 'f030-test' ) );

		$original_calls = 0;
		$args           = array(
			'permission_callback' => function () use ( &$original_calls ): bool {
				++$original_calls;
				return false;
			},
		);
		$wrapped        = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );

		$this->assertFalse( call_user_func( $wrapped['permission_callback'] ) );
		$this->assertSame( 1, $original_calls, 'Override off → original callback MUST run.' );
	}

	public function test_override_on_but_ability_not_exposed_falls_through(): void {
		// Turn override ON, but do NOT register the ability in the junction
		// table. ExposureResolver::resolve_row_only() returns false → closure must
		// fall through to the original callback.
		MCPServerQuery::instance()->update_item( $this->server_id, array( 'override_abilities_permission' => 1 ) );
		CurrentServerHolder::instance()->set( $this->fake_server( 'f030-test' ) );

		$original_calls = 0;
		$args           = array(
			'permission_callback' => function () use ( &$original_calls ): bool {
				++$original_calls;
				return false;
			},
		);
		$wrapped        = PermissionOverrideProcessor::instance()->inject_override( $args, 'not/exposed-to-this-server' );

		$this->assertFalse( call_user_func( $wrapped['permission_callback'] ) );
		$this->assertSame( 1, $original_calls, 'Not exposed → original callback MUST run even when override is ON.' );
	}

	public function test_override_on_and_exposed_returns_true_regardless_of_original(): void {
		// Turn override ON, expose the ability to this server, and register
		// an original callback that would deny. The closure MUST return true
		// without invoking the original.
		MCPServerQuery::instance()->update_item( $this->server_id, array( 'override_abilities_permission' => 1 ) );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'foo/bar', true );
		CurrentServerHolder::instance()->set( $this->fake_server( 'f030-test' ) );

		$original_calls = 0;
		$args           = array(
			'permission_callback' => function () use ( &$original_calls ): bool {
				++$original_calls;
				return false; // Original would deny — must be bypassed.
			},
		);
		$wrapped        = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );

		$this->assertTrue( call_user_func( $wrapped['permission_callback'] ), 'Override MUST return bool true, not truthy.' );
		$this->assertSame( 0, $original_calls, 'Original callback MUST NOT run when override wins.' );
	}

	public function test_missing_original_callback_falls_back_to_false_when_no_override(): void {
		// No permission_callback + null server context → call_original(null)
		// returns false (matches WP Abilities API deny-by-default).
		$args    = array( 'other_field' => 'value' );
		$wrapped = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );

		$this->assertFalse( call_user_func( $wrapped['permission_callback'] ) );
	}

	/**
	 * Regression — the wrapping closure had signature `static function ()` which
	 * silently dropped the caller's args. Downstream callbacks that inspect
	 * their input (e.g. Execute::check_permission reading `$input['ability_name']`)
	 * saw an empty array and produced a WP_Error, which call_original then
	 * coerced to true — see test_wp_error_from_original_is_preserved_not_coerced.
	 */
	public function test_closure_forwards_args_to_original_callback(): void {
		$captured = null;
		$args     = array(
			'permission_callback' => function ( $input = null ) use ( &$captured ): bool {
				$captured = $input;
				return true;
			},
		);

		$wrapped = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );
		$input   = array(
			'ability_name' => 'foo/bar',
			'parameters'   => array( 'x' => 1 ),
		);
		call_user_func_array( $wrapped['permission_callback'], array( $input ) );

		$this->assertSame(
			$input,
			$captured,
			'The wrapping closure MUST forward its args to the original permission_callback.'
		);
	}

	/**
	 * Regression — `(bool) call_user_func( $original )` in call_original() cast
	 * every object return (including WP_Error) to boolean true, silently
	 * converting a deny into an allow at the vendor's
	 * `if ( true !== $permission )` check.
	 */
	public function test_wp_error_from_original_is_preserved_not_coerced_to_true(): void {
		$args = array(
			'permission_callback' => function (): WP_Error {
				return new WP_Error( 'test_deny', 'test WP_Error preservation' );
			},
		);

		$wrapped = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );
		$result  = call_user_func( $wrapped['permission_callback'] );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A WP_Error from the original callback MUST propagate. Casting to bool silently grants access.'
		);
		$this->assertSame( 'test_deny', $result->get_error_code() );
	}

	/**
	 * Full regression across the five stock WordPress roles.
	 *
	 * The original callback mirrors Execute::check_permission's real shape:
	 * refuses when input lacks ability_name (returning WP_Error), otherwise
	 * checks a role-scoped capability. Against the buggy F030 wrapper:
	 *   (1) the closure dropped the input array,
	 *   (2) call_original cast the resulting WP_Error to true,
	 * causing every role — including subscriber — to be granted access.
	 * After the fix, only administrator (which has `manage_options`) is
	 * allowed; every other role receives the false the original produced.
	 *
	 * @dataProvider provide_wp_roles
	 */
	public function test_wrapper_preserves_role_gated_denials( string $role, bool $expected_allowed ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$args = array(
			'permission_callback' => function ( $input = array() ) {
				if ( ! is_array( $input ) || empty( $input['ability_name'] ) ) {
					return new WP_Error( 'missing_ability_name', 'ability_name required' );
				}
				return current_user_can( 'manage_options' );
			},
		);

		$wrapped   = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );
		$call_args = array(
			'ability_name' => 'foo/bar',
			'parameters'   => array(),
		);
		$result    = call_user_func_array( $wrapped['permission_callback'], array( $call_args ) );

		if ( $expected_allowed ) {
			$this->assertTrue(
				$result,
				"Role {$role} has manage_options — wrapper MUST return true."
			);
		} else {
			$this->assertFalse(
				$result,
				"Role {$role} lacks manage_options — wrapper MUST return false, not silently allow via WP_Error coercion."
			);
		}
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function provide_wp_roles(): array {
		return array(
			'subscriber'    => array( 'subscriber', false ),
			'contributor'   => array( 'contributor', false ),
			'author'        => array( 'author', false ),
			'editor'        => array( 'editor', false ),
			'administrator' => array( 'administrator', true ),
		);
	}

	public function test_clear_request_cache_returns_passthrough(): void {
		// rest_post_dispatch passes a payload — must not be mutated.
		$this->assertSame( 'payload', PermissionOverrideProcessor::instance()->clear_request_cache( 'payload' ) );
		// shutdown fires with no args.
		$this->assertNull( PermissionOverrideProcessor::instance()->clear_request_cache() );
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
