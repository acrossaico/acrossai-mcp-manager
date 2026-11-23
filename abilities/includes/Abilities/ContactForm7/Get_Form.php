<?php
/**
 * Feature 103 — read one contact form in full.
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
 * contact-form-7/get-form — all five properties plus shortcode and config errors.
 */
class Get_Form extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'get-form';
	}

	protected function ability_label(): string {
		return __( 'Get Contact Form', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return everything about one form: the form template with its field tags, both mail templates, the validation messages, the additional settings, the shortcode and any configuration errors. Read this before changing a form — the update abilities patch individual properties, and knowing the current template is what makes a field edit safe.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-forms';
	}

	protected function cf7_cap(): string {
		return 'read_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'form_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Form post ID, from contact-form-7/list-forms.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form'          => array( 'type' => 'object' ),
			'config_errors' => array( 'type' => 'array' ),
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

		return array(
			'form'          => Form_Repository::describe( $form ),
			'config_errors' => Form_Repository::config_errors( $form ),
			'message'       => sprintf(
				/* translators: %s: form title */
				__( 'Contact form "%s".', 'acrossai-abilities-manager' ),
				$form->title()
			),
		);
	}
}
