<?php
/**
 * Feature 106 — List SEO Settings Areas.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/list-settings-areas — List SEO Settings Areas.
 */
final class List_Settings_Areas extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/list-settings-areas';
	}

	protected function ability_label(): string {
		return __( 'List SEO Settings Areas', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every settings area this suite can read or write, with the keys each owns and the ability that writes it. The discovery call: rather than guessing which of the twelve update abilities owns a given key, call this once and read the mapping.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-settings';
	}

	protected function input_properties(): array {
		return array(
		);
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

	protected function run( array $input ) {
		$rows = array();

		foreach ( Settings_Repository::areas() as $area => $keys ) {
			$rows[] = array(
				'area'    => (string) $area,
				'ability' => Settings_Repository::writer_for( (string) $area ),
				'keys'    => array_keys( $keys ),
				'count'   => count( $keys ),
			);
		}

		return array(
			'areas'   => $rows,
			'count'   => count( $rows ),
			'message' => sprintf(
				/* translators: %d: number of areas */
				__( '%d settings areas. Each names the ability that writes it.', 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
