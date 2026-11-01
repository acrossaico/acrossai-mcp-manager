<?php
/**
 * Naming the plugin behind a hook, so a report can say "Wordfence" instead of
 * "a security plugin" and the operator does not bisect thirty plugins by hand.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Troubleshoot;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\PluginNamer;
use WP_UnitTestCase;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Troubleshoot\PluginNamer
 */
final class PluginNamerTest extends WP_UnitTestCase {

	private const HOOK = 'acrossai_test_namer_hook';

	public function tear_down(): void {
		remove_all_filters( self::HOOK );
		parent::tear_down();
	}

	public function test_an_unhooked_filter_names_nobody(): void {
		self::assertSame( array(), PluginNamer::plugins_hooked_to( self::HOOK ) );
	}

	/**
	 * A callback defined inside the test suite is not inside any plugin
	 * directory, so it resolves to nothing rather than to a wrong name. A
	 * namer that guesses is worse than one that stays quiet: the operator
	 * would deactivate the plugin it named and the problem would remain.
	 */
	public function test_a_callback_outside_the_plugins_directory_names_nobody(): void {
		add_filter( self::HOOK, static function ( $value ) {
			return $value;
		} );

		self::assertSame( array(), PluginNamer::plugins_hooked_to( self::HOOK ) );
	}

	/**
	 * Our own callbacks are excluded deliberately. This plugin asking the
	 * question and then naming itself as the answer helps nobody.
	 */
	public function test_this_plugins_own_callbacks_are_excluded(): void {
		add_filter(
			self::HOOK,
			array( \AcrossAI_MCP_Manager\Includes\Troubleshoot\PluginNamer::class, 'plugins_hooked_to' )
		);

		self::assertNotContains( 'AcrossAI MCP Manager', PluginNamer::plugins_hooked_to( self::HOOK ) );
	}

	/**
	 * Every callback shape WordPress accepts must be survivable. A fatal here
	 * would take down the one screen an operator reaches when things are
	 * already broken.
	 *
	 * @dataProvider callback_provider
	 *
	 * @param mixed $callback Anything add_filter() accepts.
	 */
	public function test_every_callback_shape_is_handled_without_fataling( $callback ): void {
		add_filter( self::HOOK, $callback );

		$names = PluginNamer::plugins_hooked_to( self::HOOK );

		self::assertIsArray( $names );
		foreach ( $names as $name ) {
			self::assertIsString( $name );
			self::assertNotSame( '', $name );
		}
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function callback_provider(): array {
		return array(
			'closure'           => array( static function ( $v ) {
				return $v;
			} ),
			'function name'     => array( '__return_true' ),
			'static as string'  => array( 'WP_Hook::build_preinitialized_hooks' ),
			'array callable'    => array( array( \AcrossAI_MCP_Manager\Includes\Troubleshoot\PluginNamer::class, 'plugins_hooked_to' ) ),
			'nonexistent'       => array( 'acrossai_no_such_function_at_all' ),
		);
	}

	/**
	 * The real caller passes core hook names, which on a plain install have
	 * no plugin callbacks at all.
	 */
	public function test_core_rest_hooks_do_not_fatal(): void {
		foreach ( array( 'rest_authentication_errors', 'rest_pre_dispatch', 'rest_endpoints' ) as $hook ) {
			self::assertIsArray( PluginNamer::plugins_hooked_to( $hook ) );
		}
	}

	public function test_names_are_unique(): void {
		add_filter( self::HOOK, '__return_true' );
		add_filter( self::HOOK, '__return_false', 20 );

		$names = PluginNamer::plugins_hooked_to( self::HOOK );

		self::assertSame( array_values( array_unique( $names ) ), $names );
	}
}
