<?php
/**
 * Feature 104 — Get Object Cache Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Settings_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Toolbox_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-object-cache-status — Get Object Cache Status.
 */
final class Get_Object_Cache_Status extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-object-cache-status';
	}

	protected function ability_label(): string {
		return __( 'Get Object Cache Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Whether the object cache is enabled, which backend it targets, its host and port, and whether LiteSpeed\'s drop-in file is actually installed. Configured and working are different things: a misconfigured backend silently falls back to the database and the only symptom is that the site is slow.',
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
			'settings'         => array( 'type' => 'array' ),

			'dropin_installed' => array( 'type' => 'boolean' ),
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
		return array(
			'settings'         => Settings_Repository::describe_area( 'object-cache' ),
			'dropin_installed' => Toolbox_Repository::object_cache_dropin_installed(),
			'message'          => Settings_Repository::value( 'object' )
				? __( 'Object cache is enabled. Call litespeed/test-object-cache-connection to confirm it is reachable.', 'acrossai-abilities-manager' )
				: __( 'Object cache is disabled.', 'acrossai-abilities-manager' ),
		);
	}
}
