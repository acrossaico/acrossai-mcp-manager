<?php
/**
 * Feature 103 — patch a form's validation messages.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Contact_Form_7_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/update-messages — unknown slugs are refused, not dropped.
 */
class Update_Messages extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'update-messages';
	}

	protected function ability_label(): string {
		return __( 'Update Contact Form Messages', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Change specific validation or status messages on a form, leaving the rest as they are. A slug Contact Form 7 does not recognise is rejected rather than silently ignored — get the valid slugs from contact-form-7/get-messages.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-messages';
	}

	protected function cf7_cap(): string {
		return 'edit_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'form_id'  => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'messages' => array(
				'type'        => 'object',
				'description' => __( 'Message slug => new text.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id' => array( 'type' => 'integer' ),
			'updated' => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array( 'form_id', 'messages' );
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$form_id = absint( $input['form_id'] ?? 0 );
		$form    = Form_Repository::get( $form_id );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$allowed = Contact_Form_7_Guard::assert_can_edit( $form_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$patch = isset( $input['messages'] ) && is_array( $input['messages'] ) ? $input['messages'] : array();

		if ( array() === $patch ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one message to change.', 'acrossai-abilities-manager' ) );
		}

		$known    = Form_Repository::default_messages();
		$messages = (array) $form->prop( 'messages' );
		$updated  = array();

		foreach ( $patch as $slug => $text ) {
			$slug = (string) $slug;

			if ( ! array_key_exists( $slug, $known ) ) {
				return new WP_Error(
					'unknown_message',
					sprintf(
						/* translators: %s: message slug */
						__( '"%s" is not a Contact Form 7 message slug.', 'acrossai-abilities-manager' ),
						$slug
					)
				);
			}

			$messages[ $slug ] = (string) $text;
			$updated[]         = $slug;
		}

		$saved = Form_Repository::save(
			array(
				'id'       => $form_id,
				'messages' => $messages,
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'form_id' => $form_id,
			'updated' => $updated,
			'message' => sprintf(
				/* translators: %s: comma-separated message slugs */
				__( 'Updated: %s.', 'acrossai-abilities-manager' ),
				implode( ', ', $updated )
			),
		);
	}
}
