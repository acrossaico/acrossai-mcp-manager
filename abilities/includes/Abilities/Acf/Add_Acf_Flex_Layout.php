<?php
/**
 * Feature 105 — Add Flexible Content Layout.
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
 * custom-fields/add-acf-flex-layout — Add Flexible Content Layout.
 */
final class Add_Acf_Flex_Layout extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/add-acf-flex-layout';
	}

	protected function ability_label(): string {
		return __( 'Add Flexible Content Layout', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Append one layout to a flexible-content field, or insert it at a 1-based position. The layout name must be one the field defines — call custom-fields/get-acf-field or blocks/get-acf-block-fields to see which. Rejected with a typed error on a plain repeater, which has no layouts. Requires ACF PRO.',
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
			'flexible_content',
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

			'layout'   => array(
				'type'        => 'string',
				'description' => __( 'Name of the layout to add, as defined on the flexible-content field.', 'acrossai-abilities-manager' ),
			),

			'values'   => array(
				'type'        => 'object',
				'description' => __( 'Sub-field name => value for the new layout.', 'acrossai-abilities-manager' ),
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
			'layout',
		);
	}

	protected function output_properties(): array {
		return array(
			'index'     => array( 'type' => 'integer' ),

			'layout'    => array( 'type' => 'string' ),

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
		$typed    = Field_Repository::assert_type( $selector, $target, array( 'flexible_content' ) );

		if ( is_wp_error( $typed ) ) {
			return $typed;
		}

		$layout   = (string) $input['layout'];
		$values   = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		$position = isset( $input['position'] ) ? (int) $input['position'] : null;

		// acf_layout is how ACF marks which layout a flexible-content row uses; without it the row is
		// stored but renders as nothing.
		$row = array_merge( array( 'acf_fc_layout' => $layout ), $values );

		$index = Field_Repository::add_row( $selector, (array) Slash_Input::slash( $row, $input ), $target, $position );

		if ( is_wp_error( $index ) ) {
			return $index;
		}

		return array(
			'index'     => $index,
			'layout'    => $layout,
			'row_count' => Field_Repository::row_count( $selector, $target ),
			'message'   => sprintf(
				/* translators: 1: layout name, 2: row index */
				__( 'Layout "%1$s" added at row %2$d.', 'acrossai-abilities-manager' ),
				$layout,
				$index
			),
		);
	}
}
