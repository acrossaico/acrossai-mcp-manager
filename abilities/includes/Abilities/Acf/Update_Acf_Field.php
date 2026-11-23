<?php
/**
 * Feature 105 — Update ACF Field Value.
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
 * custom-fields/update-acf-field — Update ACF Field Value.
 */
final class Update_Acf_Field extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/update-acf-field';
	}

	protected function ability_label(): string {
		return __( 'Update ACF Field Value', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Write one Advanced Custom Fields value on any target. Goes through ACF update_field(), which is the ONLY correct way to write a complex field: ACF stores a value row plus a field-key reference row, and repeaters store one row per index per sub-field. Writing post meta directly leaves those out of step and ACF can no longer read its own data — a corruption that reports success. Read the current value with custom-fields/get-acf-field first so the write is idempotent.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-fields';
	}

	protected function has_target(): bool {
		return true;
	}

	protected function is_writer(): bool {
		return true;
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
				'description' => __( 'Field name (e.g. hero_title) or field key (e.g. field_abc123). The name is what you see in the ACF admin.', 'acrossai-abilities-manager' ),
			),

			'value'    => array(
				'type'        => array( 'string', 'number', 'boolean', 'array', 'object', 'null' ),
				'description' => __( 'The new value. Shape depends on the field type: a scalar for text or number, an array of row objects for a repeater, a post ID or array of IDs for a relationship.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'selector',
			'value',
		);
	}

	protected function output_properties(): array {
		return array(
			'field'   => array( 'type' => 'object' ),

			'updated' => array( 'type' => 'boolean' ),
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
		$value    = Slash_Input::slash( $input['value'] ?? null, $input );

		$written = Field_Repository::update( $selector, $value, $target );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$field = Field_Repository::describe( $selector, $target );

		return array(
			'field'   => is_wp_error( $field ) ? null : $field,
			'updated' => true,
			'message' => sprintf(
				/* translators: 1: field name, 2: target description */
				__( 'Updated "%1$s" on %2$s.', 'acrossai-abilities-manager' ),
				$selector,
				Acf_Target::describe( $input )
			),
		);
	}
}
