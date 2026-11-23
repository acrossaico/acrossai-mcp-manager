<?php
/**
 * Feature 104 — Flush The Object Cache.
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
 * litespeed/flush-object-cache — Flush The Object Cache.
 */
final class Flush_Object_Cache extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'flush-object-cache';
	}

	protected function ability_label(): string {
		return __( 'Flush The Object Cache', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Flush the object cache through LiteSpeed\'s own handler, so its counters and hooks stay consistent. Prefer this over the generic cache/flush-object-cache ability on a site running LiteSpeed; the generic one flushes the same data but LiteSpeed does not learn that it happened.',
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
			'flushed' => array( 'type' => 'boolean' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		Toolbox_Repository::flush_object_cache();

		return array(
			'flushed' => true,
			'message' => __( 'Object cache flushed.', 'acrossai-abilities-manager' ),
		);
	}
}
