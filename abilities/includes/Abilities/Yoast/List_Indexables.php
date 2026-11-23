<?php
/**
 * Feature 106 — List Indexable Kinds.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Indexable_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/list-indexables — List Indexable Kinds.
 */
final class List_Indexables extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/list-indexables';
	}

	protected function ability_label(): string {
		return __( 'List Indexable Kinds', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every kind of indexable this suite can address, what each means and what to pass as the subject, alongside how many of each Yoast currently has indexed. The discovery call before seo/get-indexable.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexables';
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
			'kinds' => array( 'type' => 'array' ),

			'counts' => array( 'type' => 'array' ),
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
		$kinds = array();

		foreach ( Indexable_Repository::kinds() as $kind => $describes ) {
			$kinds[] = array(
				'kind'      => (string) $kind,
				'describes' => (string) $describes,
			);
		}

		return array(
			'kinds'   => $kinds,
			'counts'  => Indexable_Repository::counts(),
			'message' => sprintf(
				/* translators: %d: number of kinds */
				__( '%d indexable kinds.', 'acrossai-abilities-manager' ),
				count( $kinds )
			),
		);
	}
}
