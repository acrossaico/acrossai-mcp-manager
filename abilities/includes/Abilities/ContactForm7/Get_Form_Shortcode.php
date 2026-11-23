<?php
/**
 * Feature 103 — the shortcode that embeds a form.
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
 * contact-form-7/get-form-shortcode — the one string needed to place a form.
 */
class Get_Form_Shortcode extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'get-form-shortcode';
	}

	protected function ability_label(): string {
		return __( 'Get Contact Form Shortcode', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return the shortcode that embeds a form, ready to insert into a post or page. Contact Form 7 forms do not render on their own — the shortcode is how one reaches a visitor, so this is the last step after creating a form.', 'acrossai-abilities-manager' );
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
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id'   => array( 'type' => 'integer' ),
			'shortcode' => array( 'type' => 'string' ),
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
			'form_id'   => (int) $form->id(),
			'shortcode' => (string) $form->shortcode(),
			'message'   => __( 'Insert this shortcode into a post or page to show the form.', 'acrossai-abilities-manager' ),
		);
	}
}
