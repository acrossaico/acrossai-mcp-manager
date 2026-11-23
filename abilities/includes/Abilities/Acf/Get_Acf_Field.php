<?php
/**
 * Feature 105 — Get ACF Field Value.
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
 * custom-fields/get-acf-field — Get ACF Field Value.
 */
final class Get_Acf_Field extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/get-acf-field';
	}

	protected function ability_label(): string {
		return __( 'Get ACF Field Value', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read one Advanced Custom Fields value from any target: a post, user, term, comment or the options store. Goes through ACF get_field(), so repeaters, flexible content, clones, relationships, post objects and image fields all hydrate to their proper shape rather than the raw stored scalar. Use this before writing, so an update can be idempotent. Returns the field name, key, type, label and value.',
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
			'field' => array( 'type' => 'object' ),
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

		$selector = (string) $input['selector'];
		$field    = Field_Repository::describe( $selector, $target );

		if ( is_wp_error( $field ) ) {
			return $field;
		}

		return array(
			'field'   => $field,
			'message' => sprintf(
				/* translators: 1: field name, 2: target description */
				__( 'Read "%1$s" from %2$s.', 'acrossai-abilities-manager' ),
				$selector,
				Acf_Target::describe( $input )
			),
		);
	}
}
