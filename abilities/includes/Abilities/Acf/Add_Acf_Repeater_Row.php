<?php
/**
 * Feature 105 — Add Repeater Row.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Field_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Acf_Target;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * custom-fields/add-acf-repeater-row — Add Repeater Row.
 */
final class Add_Acf_Repeater_Row extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/add-acf-repeater-row';
	}

	protected function ability_label(): string {
		return __( 'Add Repeater Row', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Append one row to a repeater or flexible-content field, or insert it at a 1-based position. Saves the read-modify-write of the whole array that adding a row would otherwise need: three round trips become one call, and a downstream diff stays readable. Note ACF has no positional-insert API — appending is a single targeted write, while inserting rewrites the array server-side, so append when order does not matter. Requires ACF PRO.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-rows';
	}

	protected function has_target(): bool {
		return true;
	}

	protected function is_writer(): bool {
		return true;
	}

	protected function requires_pro(): bool {
		return true;
	}

	protected function required_field_types(): array {
		return array(
			'repeater',
		);
	}

	protected function suggested_abilities(): array {
		return array(
			'custom-fields/get-acf-field',
		);
	}

	protected function input_properties(): array {
		return array(
			'selector' => array(
				'type'        => 'string',
				'description' => __( 'Repeater or flexible-content field name (e.g. cards) or field key.', 'acrossai-abilities-manager' ),
			),

			'row'      => array(
				'type'        => 'object',
				'description' => __( 'Sub-field name => value for the new row.', 'acrossai-abilities-manager' ),
			),

			'position' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( '1-based position to insert at. Omit to append.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'selector',
			'row',
		);
	}

	protected function output_properties(): array {
		return array(
			'index'     => array( 'type' => 'integer' ),

			'row_count' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	protected function run( array $input ) {
		$target = Acf_Target::resolve( $input );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$selector = (string) $input['selector'];
		$typed    = Field_Repository::assert_type( $selector, $target, array( 'repeater', 'flexible_content' ) );

		if ( is_wp_error( $typed ) ) {
			return $typed;
		}

		$row      = isset( $input['row'] ) && is_array( $input['row'] ) ? $input['row'] : array();
		$position = isset( $input['position'] ) ? (int) $input['position'] : null;

		$index = Field_Repository::add_row( $selector, (array) Slash_Input::slash( $row, $input ), $target, $position );

		if ( is_wp_error( $index ) ) {
			return $index;
		}

		return array(
			'index'     => $index,
			'row_count' => Field_Repository::row_count( $selector, $target ),
			'message'   => sprintf(
				/* translators: 1: row index, 2: field name */
				__( 'Row %1$d added to "%2$s".', 'acrossai-abilities-manager' ),
				$index,
				$selector
			),
		);
	}
}
