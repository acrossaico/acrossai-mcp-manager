<?php
/**
 * Feature 105 — Get All ACF Field Values.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Field_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Acf_Target;

defined( 'ABSPATH' ) || exit;

/**
 * custom-fields/get-acf-fields — Get All ACF Field Values.
 */
final class Get_Acf_Fields extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/get-acf-fields';
	}

	protected function ability_label(): string {
		return __( 'Get All ACF Field Values', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read every Advanced Custom Fields value attached to a target in one call — a post, user, term, comment or the options store. Returns a list of rows, each with the field name, key, type, label and hydrated value, so a caller can discover what fields exist before deciding what to change. Prefer this over guessing field names.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-fields';
	}

	protected function has_target(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'fields' => array( 'type' => 'array' ),

			'count'  => array( 'type' => 'integer' ),
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
		$target = Acf_Target::resolve( $input );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$rows = Field_Repository::all( $target );

		return array(
			'fields'  => $rows,
			'count'   => count( $rows ),
			'message' => sprintf(
				/* translators: 1: number of fields, 2: target description */
				__( '%1$d field(s) on %2$s.', 'acrossai-abilities-manager' ),
				count( $rows ),
				Acf_Target::describe( $input )
			),
		);
	}
}
