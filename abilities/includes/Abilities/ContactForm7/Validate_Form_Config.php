<?php
/**
 * Feature 103 — run Contact Form 7's own configuration validator.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/validate-form-config — the natural last step of any edit.
 */
class Validate_Form_Config extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'validate-form-config';
	}

	protected function ability_label(): string {
		return __( 'Validate Contact Form Configuration', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Run Contact Form 7\'s own configuration validator against a form and return what it finds, grouped by the property at fault — an unsafe email sender, a mail tag in the wrong place, an attachment path that does not exist. Run this after editing a form: it is the difference between "the form saved" and "the form works".', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-settings';
	}

	protected function cf7_cap(): string {
		return 'read_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'form_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id' => array( 'type' => 'integer' ),
			'valid'   => array( 'type' => 'boolean' ),
			'errors'  => array( 'type' => 'array' ),
			'count'   => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array( 'form_id' );
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$form = Form_Repository::get( absint( $input['form_id'] ?? 0 ) );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$errors = Form_Repository::config_errors( $form );

		return array(
			'form_id' => (int) $form->id(),
			'valid'   => array() === $errors,
			'errors'  => $errors,
			'count'   => count( $errors ),
			'message' => array() === $errors
				? __( 'Contact Form 7 reports no configuration problems.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of errors */
					_n( '%d configuration problem.', '%d configuration problems.', count( $errors ), 'acrossai-abilities-manager' ),
					count( $errors )
				),
		);
	}
}
