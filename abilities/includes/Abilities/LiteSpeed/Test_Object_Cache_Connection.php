<?php
/**
 * Feature 104 — Test Object Cache Connection.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Toolbox_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/test-object-cache-connection — Test Object Cache Connection.
 */
final class Test_Object_Cache_Connection extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'test-object-cache-connection';
	}

	protected function ability_label(): string {
		return __( 'Test Object Cache Connection', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Open a connection to the configured object-cache backend and report whether it succeeded. Read-only, and the call to make immediately after changing the backend settings — LiteSpeed does not warn when it cannot connect, it just stops using the cache.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-object';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'connected' => array( 'type' => 'boolean' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$connected = Toolbox_Repository::test_object_cache();

		return array(
			'connected' => $connected,
			'message'   => $connected
				? __( 'Connected to the object cache backend.', 'acrossai-abilities-manager' )
				: __( 'Could not connect. Check the host, port and credentials — the site is running without an object cache.', 'acrossai-abilities-manager' ),
		);
	}
}
