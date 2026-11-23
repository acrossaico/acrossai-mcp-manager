<?php
/**
 * Feature 105 — Remove Flexible Content Layout.
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
 * custom-fields/remove-acf-flex-layout — Remove Flexible Content Layout.
 */
final class Remove_Acf_Flex_Layout extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/remove-acf-flex-layout';
	}

	protected function ability_label(): string {
		return __( 'Remove Flexible Content Layout', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Remove one layout from a flexible-content field by its 1-based index. Later layouts shift down by one. Rejected with a typed error on a plain repeater — use custom-fields/remove-acf-repeater-row for those. Requires ACF PRO.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-rows';
	}

	protected function has_target(): bool {
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

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __(
			'Removing a layout deletes its values and cannot be undone. Pass confirm: true to proceed.',
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
				'description' => __( 'Repeater or flexible-content field name (e.g. cards) or field key.', 'acrossai-abilities-manager' ),
			),

			'index'    => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( '1-based row index, matching ACF\'s own API. The first row is 1, not 0.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'selector',
			'index',
		);
	}

	protected function output_properties(): array {
		return array(
			'removed_index' => array( 'type' => 'integer' ),

			'row_count'     => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
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

		$index = (int) $input['index'];
		$range = Field_Repository::assert_index( $index, Field_Repository::row_count( $selector, $target ) );

		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$removed = Field_Repository::delete_row( $selector, $index, $target );

		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		return array(
			'removed_index' => $index,
			'row_count'     => Field_Repository::row_count( $selector, $target ),
			'message'       => sprintf(
				/* translators: 1: row index, 2: field name */
				__( 'Layout %1$d removed from "%2$s".', 'acrossai-abilities-manager' ),
				$index,
				$selector
			),
		);
	}
}
