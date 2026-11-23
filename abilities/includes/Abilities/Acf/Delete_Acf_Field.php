<?php
/**
 * Feature 105 — Delete ACF Field Value.
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
 * custom-fields/delete-acf-field — Delete ACF Field Value.
 */
final class Delete_Acf_Field extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/delete-acf-field';
	}

	protected function ability_label(): string {
		return __( 'Delete ACF Field Value', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Clear one Advanced Custom Fields value on any target. Goes through ACF delete_field(), which also removes the field-key reference row and, for a repeater, every sub-field row underneath — none of which a plain post-meta delete would touch, leaving orphaned rows behind. The field definition itself is untouched; only the stored value is removed.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-fields';
	}

	protected function has_target(): bool {
		return true;
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __(
			'Clearing a field value cannot be undone. Pass confirm: true to proceed.',
			'acrossai-abilities-manager'
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
				'description' => __( 'Field name (e.g. hero_title) or field key (e.g. field_abc123). The name is what you see in the ACF admin.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'selector',
		);
	}

	protected function output_properties(): array {
		return array(
			'deleted' => array( 'type' => 'boolean' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$target = Acf_Target::resolve( $input );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$selector = (string) $input['selector'];

		if ( ! Field_Repository::exists( $selector, $target ) ) {
			return new \WP_Error(
				'unknown_field',
				sprintf(
					/* translators: %s: field name */
					__( 'No ACF field "%s" is attached to that target.', 'acrossai-abilities-manager' ),
					$selector
				)
			);
		}

		$deleted = Field_Repository::delete( $selector, $target );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return array(
			'deleted' => true,
			'message' => sprintf(
				/* translators: 1: field name, 2: target description */
				__( 'Cleared "%1$s" on %2$s.', 'acrossai-abilities-manager' ),
				$selector,
				Acf_Target::describe( $input )
			),
		);
	}
}
