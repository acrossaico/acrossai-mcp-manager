<?php
/**
 * Feature 105 — Update Multiple ACF Field Values.
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
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * custom-fields/update-acf-fields — Update Multiple ACF Field Values.
 */
final class Update_Acf_Fields extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/update-acf-fields';
	}

	protected function ability_label(): string {
		return __( 'Update Multiple ACF Field Values', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Write several Advanced Custom Fields values on one target in a single call. Returns per-field updated and failed buckets, so a partial success is visible rather than collapsing to one boolean. Same correctness guarantee as custom-fields/update-acf-field: every write goes through ACF rather than post meta. Read the current values with custom-fields/get-acf-fields first.',
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
			'custom-fields/get-acf-fields',
		);
	}

	protected function input_properties(): array {
		return array(
			'fields' => array(
				'type'        => 'object',
				'description' => __( 'Field name or key => new value. At least one entry required.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'fields',
		);
	}

	protected function output_properties(): array {
		return array(
			'updated' => array( 'type' => 'array' ),

			'failed'  => array( 'type' => 'array' ),
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

		$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();

		if ( array() === $fields ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one field to change.', 'acrossai-abilities-manager' ) );
		}

		$updated = array();
		$failed  = array();

		foreach ( $fields as $selector => $value ) {
			$selector = (string) $selector;
			$written  = Field_Repository::update( $selector, Slash_Input::slash( $value, $input ), $target );

			if ( is_wp_error( $written ) ) {
				$failed[] = array(
					'field'  => $selector,
					'reason' => $written->get_error_message(),
				);
				continue;
			}

			$updated[] = $selector;
		}

		return array(
			'updated' => $updated,
			'failed'  => $failed,
			'message' => sprintf(
				/* translators: 1: number updated, 2: number failed */
				__( '%1$d field(s) updated, %2$d failed.', 'acrossai-abilities-manager' ),
				count( $updated ),
				count( $failed )
			),
		);
	}
}
