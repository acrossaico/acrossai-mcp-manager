<?php
/**
 * Feature 104 — Get Cache Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Settings_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Purge_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-cache-status — Get Cache Status.
 */
final class Get_Cache_Status extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-cache-status';
	}

	protected function ability_label(): string {
		return __( 'Get Cache Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Whether LiteSpeed is caching, what it is caching for whom, the configured TTLs, whether the object cache and browser cache are on, and whether a QUIC.cloud key is present. The orientation call: run this before changing anything, and again afterwards to confirm the change took. Reports the presence of a QUIC.cloud key but never uses it.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'status'        => array( 'type' => 'array' ),

			'purge_targets' => array( 'type' => 'array' ),
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
		$rows = array();

		foreach ( array( 'cache-general', 'cache-scope', 'cache-ttl', 'browser', 'object-cache', 'guest' ) as $area ) {
			$rows = array_merge( $rows, Settings_Repository::describe_area( $area ) );
		}

		$targets = array();

		foreach ( Purge_Repository::targets() as $target => $describes ) {
			$targets[] = array(
				'target'    => $target,
				'describes' => $describes,
			);
		}

		return array(
			'status'        => $rows,
			'purge_targets' => $targets,
			'message'       => Settings_Repository::value( 'cache' )
				? __( 'LiteSpeed caching is on.', 'acrossai-abilities-manager' )
				: __( 'LiteSpeed caching is OFF — nothing is being cached.', 'acrossai-abilities-manager' ),
		);
	}
}
