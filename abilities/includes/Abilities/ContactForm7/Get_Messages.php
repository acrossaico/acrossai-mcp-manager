<?php
/**
 * Feature 103 — read a form's validation messages.
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
 * contact-form-7/get-messages — current text alongside CF7's defaults.
 */
class Get_Messages extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'get-messages';
	}

	protected function ability_label(): string {
		return __( 'Get Contact Form Messages', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return a form\'s validation and status messages — what a visitor sees on success, on failure, when a required field is empty and so on. Each entry shows the current text next to Contact Form 7\'s default, so it is obvious which have been customised.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-messages';
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
			'messages' => array( 'type' => 'array' ),
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

		$current  = (array) $form->prop( 'messages' );
		$defaults = Form_Repository::default_messages();
		$rows     = array();

		foreach ( $defaults as $slug => $default ) {
			$text = isset( $current[ $slug ] ) ? (string) $current[ $slug ] : $default;

			$rows[] = array(
				'slug'         => $slug,
				'text'         => $text,
				'default'      => $default,
				'customised'   => $text !== $default,
			);
		}

		return array(
			'form_id'  => (int) $form->id(),
			'messages' => $rows,
			'message'  => sprintf(
				/* translators: %d: number of messages */
				_n( '%d message.', '%d messages.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
