<?php
/**
 * Feature 103 — the raw form template.
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
 * contact-form-7/get-form-template — markup, for callers editing it directly.
 */
class Get_Form_Template extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'get-form-template';
	}

	protected function ability_label(): string {
		return __( 'Get Contact Form Template', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return a form\'s raw template markup, tags and surrounding HTML included. Use contact-form-7/list-form-fields instead when you only need to know what fields exist; read this when you intend to rewrite the layout itself.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-fields';
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
			'form_id'  => array( 'type' => 'integer' ),
			'template' => array( 'type' => 'string' ),
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
			'form_id'  => (int) $form->id(),
			'template' => (string) $form->prop( 'form' ),
			'message'  => __( 'Form template returned.', 'acrossai-abilities-manager' ),
		);
	}
}
