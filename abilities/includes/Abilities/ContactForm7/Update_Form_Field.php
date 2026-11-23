<?php
/**
 * Feature 103 — change one field in a form template.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Contact_Form_7_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Tag_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/update-form-field — replaces one tag, leaving the layout alone.
 */
class Update_Form_Field extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'update-form-field';
	}

	protected function ability_label(): string {
		return __( 'Update Contact Form Field', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Change one field of a form in place: its type, whether it is required, its options or its choices. Only that field\'s tag is rewritten — the surrounding markup, labels and other fields are left byte for byte as they were. Renaming a field is deliberately not offered here: the old name may appear in the mail templates, and a rename that leaves those behind breaks the notification silently. Remove and re-add instead, then check with contact-form-7/validate-mail-tags.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-fields';
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
			'name'     => array(
				'type'        => 'string',
				'description' => __( 'Name of the field to change.', 'acrossai-abilities-manager' ),
			),
			'type'     => array( 'type' => 'string' ),
			'required' => array( 'type' => 'boolean' ),
			'options'  => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Tag options, e.g. class:wide, id:enquiry-phone or maxlength:40. Write each as key:value; a value containing spaces is quoted for you, and one with a space but no key is refused.', 'acrossai-abilities-manager' ),
			),
			'values'   => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id'  => array( 'type' => 'integer' ),
			'field'    => array( 'type' => 'object' ),
			'tag'      => array( 'type' => 'string' ),
			'template' => array( 'type' => 'string' ),
		);
	}

	protected function required_input(): array {
		return array( 'form_id', 'name' );
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

		$name    = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$current = null;

		foreach ( Form_Tag_Repository::tags( $form ) as $candidate ) {
			if ( $name === $candidate['name'] ) {
				$current = $candidate;
				break;
			}
		}

		if ( null === $current ) {
			return new WP_Error(
				'field_not_found',
				sprintf(
					/* translators: %s: field name */
					__( 'No field named "%s" in this form.', 'acrossai-abilities-manager' ),
					$name
				)
			);
		}

		$type = isset( $input['type'] )
			? sanitize_text_field( (string) $input['type'] )
			: (string) $current['basetype'];

		if ( ! Form_Tag_Repository::type_exists( $type ) ) {
			return new WP_Error(
				'unknown_field_type',
				sprintf(
					/* translators: %s: field type */
					__( '"%s" is not a field type on this site.', 'acrossai-abilities-manager' ),
					$type
				)
			);
		}

		$invalid_options = Form_Tag_Repository::invalid_options(
			isset( $input['options'] ) ? (array) $input['options'] : array()
		);

		if ( array() !== $invalid_options ) {
			return new WP_Error(
				'invalid_option',
				sprintf(
					/* translators: %s: comma-separated offending options */
					__( 'CF7 splits a form tag on whitespace, so an option containing a space must be written as key:value and will be quoted for you. These have no key to quote: %s', 'acrossai-abilities-manager' ),
					implode( ', ', $invalid_options )
				)
			);
		}

		$tag = Form_Tag_Repository::compose_tag(
			$type,
			$name,
			array_key_exists( 'required', $input ) ? (bool) $input['required'] : (bool) $current['required'],
			array_key_exists( 'options', $input ) ? (array) $input['options'] : (array) $current['options'],
			array_key_exists( 'values', $input ) ? (array) $input['values'] : (array) $current['values']
		);

		$template = Form_Tag_Repository::replace_tag( (string) $form->prop( 'form' ), $name, $tag );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$saved = Form_Repository::save(
			array(
				'id'   => $form_id,
				'form' => $template,
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$fresh = Form_Repository::get( $form_id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$field = array();

		foreach ( Form_Tag_Repository::tags( $fresh ) as $candidate ) {
			if ( $name === $candidate['name'] ) {
				$field = $candidate;
				break;
			}
		}

		return array(
			'form_id'  => $form_id,
			'field'    => $field,
			'tag'      => $tag,
			'template' => (string) $fresh->prop( 'form' ),
			'message'  => sprintf(
				/* translators: %s: field name */
				__( 'Updated field "%s".', 'acrossai-abilities-manager' ),
				$name
			),
		);
	}
}
