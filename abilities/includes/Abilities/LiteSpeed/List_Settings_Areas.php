<?php
/**
 * Feature 104 — List Settings Areas.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/list-settings-areas — List Settings Areas.
 */
final class List_Settings_Areas extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'list-settings-areas';
	}

	protected function ability_label(): string {
		return __( 'List Settings Areas', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every settings area this suite can read or write, with the option keys each one owns and which ability writes it. The discovery call for a parameterised suite: rather than guessing which of the twelve update abilities owns a given option, call this once and read the mapping.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-toolbox';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'areas' => array( 'type' => 'array' ),

			'count' => array( 'type' => 'integer' ),
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

		foreach ( Settings_Repository::areas() as $area => $keys ) {
			$rows[] = array(
				'area'  => $area,
				'keys'  => array_keys( $keys ),
				'count' => count( $keys ),
			);
		}

		return array(
			'areas'   => $rows,
			'count'   => count( $rows ),
			'message' => sprintf(
				/* translators: %d: number of areas */
				__( '%d settings areas.', 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
