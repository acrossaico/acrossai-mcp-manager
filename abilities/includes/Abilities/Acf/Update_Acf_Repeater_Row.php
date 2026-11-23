<?php
/**
 * Feature 105 — Update Repeater Row.
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
 * custom-fields/update-acf-repeater-row — Update Repeater Row.
 */
final class Update_Acf_Repeater_Row extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/update-acf-repeater-row';
	}

	protected function ability_label(): string {
		return __( 'Update Repeater Row', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Patch one row of a repeater or flexible-content field by its 1-based index. Sub-fields you do not name are preserved, so this is a patch rather than a replace. Read the field with custom-fields/get-acf-field first to learn the valid index range — rows are 1-based and an out-of-range index is refused rather than silently ignored. Requires ACF PRO.',
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

			'index'    => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( '1-based row index, matching ACF\'s own API. The first row is 1, not 0.', 'acrossai-abilities-manager' ),
			),

			'row'      => array(
				'type'        => 'object',
				'description' => __( 'Sub-field name => new value. Omitted sub-fields keep their current values.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'selector',
			'index',
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
			'idempotent'  => true,
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

		$index = (int) $input['index'];
		$count = Field_Repository::row_count( $selector, $target );
		$range = Field_Repository::assert_index( $index, $count );

		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$row     = isset( $input['row'] ) && is_array( $input['row'] ) ? $input['row'] : array();
		$written = Field_Repository::update_row( $selector, $index, (array) Slash_Input::slash( $row, $input ), $target );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'index'     => $index,
			'row_count' => $count,
			'message'   => sprintf(
				/* translators: 1: row index, 2: field name */
				__( 'Row %1$d of "%2$s" updated.', 'acrossai-abilities-manager' ),
				$index,
				$selector
			),
		);
	}
}
