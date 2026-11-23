<?php
/**
 * Feature 103 — read a mail template.
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
 * contact-form-7/get-mail — one of the two mail templates.
 */
class Get_Mail extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'get-mail';
	}

	protected function ability_label(): string {
		return __( 'Get Contact Form Mail Template', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return one of a form\'s two mail templates: recipient, sender, subject, body, extra headers, attachments and the HTML and exclude-blank flags. "mail" is the notification sent to the site; "mail_2" is the optional autoresponder to the visitor and may be inactive.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-mail';
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
			'which'   => array(
				'type'    => 'string',
				'enum'    => array( 'mail', 'mail_2' ),
				'default' => 'mail',
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id' => array( 'type' => 'integer' ),
			'which'   => array( 'type' => 'string' ),
			'mail'    => array( 'type' => 'object' ),
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

		$which = isset( $input['which'] ) && 'mail_2' === $input['which'] ? 'mail_2' : 'mail';

		return array(
			'form_id' => (int) $form->id(),
			'which'   => $which,
			'mail'    => (array) $form->prop( $which ),
			'message' => sprintf(
				/* translators: %s: mail or mail_2 */
				__( 'Mail template "%s" returned.', 'acrossai-abilities-manager' ),
				$which
			),
		);
	}
}
