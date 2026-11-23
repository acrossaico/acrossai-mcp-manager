<?php
/**
 * Feature 103 — patch a contact form's properties.
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
 * contact-form-7/update-form — change any subset of a form's properties.
 */
class Update_Form extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'update-form';
	}

	protected function ability_label(): string {
		return __( 'Update Contact Form', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Change any subset of a form\'s properties: title, locale, form template, either mail template, validation messages or additional settings. Properties you omit are left exactly as they are. Read the form first with contact-form-7/get-form — this replaces whole properties rather than merging into them, so sending a partial mail object would drop the fields you left out.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-forms';
	}

	protected function cf7_cap(): string {
		return 'edit_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'form_id'             => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'title'               => array( 'type' => 'string' ),
			'locale'              => array( 'type' => 'string' ),
			'form'                => array( 'type' => 'string' ),
			'mail'                => array( 'type' => 'object' ),
			'mail_2'              => array( 'type' => 'object' ),
			'messages'            => array( 'type' => 'object' ),
			'additional_settings' => array( 'type' => 'string' ),
		);
	}

	protected function output_properties(): array {
		return array(
			'form'          => array( 'type' => 'object' ),
			'updated'       => array( 'type' => 'array' ),
			'config_errors' => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array( 'form_id' );
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'contact-form-7/get-form',
				'reason' => 'Read the current properties first — this ability replaces whole properties rather than merging into them.',
			),
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

		$data    = array( 'id' => $form_id );
		$updated = array();

		foreach ( array( 'title', 'locale', 'form', 'additional_settings' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$data[ $key ] = 'title' === $key
					? sanitize_text_field( (string) $input[ $key ] )
					: (string) $input[ $key ];
				$updated[]    = $key;
			}
		}

		foreach ( array( 'mail', 'mail_2', 'messages' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
				$data[ $key ] = $input[ $key ];
				$updated[]    = $key;
			}
		}

		if ( array() === $updated ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one property to change.', 'acrossai-abilities-manager' ) );
		}

		$saved = Form_Repository::save( $data );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$fresh = Form_Repository::get( $form_id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		return array(
			'form'          => Form_Repository::describe( $fresh ),
			'updated'       => $updated,
			'config_errors' => Form_Repository::config_errors( $fresh ),
			'message'       => sprintf(
				/* translators: %s: comma-separated property names */
				__( 'Updated: %s.', 'acrossai-abilities-manager' ),
				implode( ', ', $updated )
			),
		);
	}
}
