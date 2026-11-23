<?php
/**
 * Feature 103 — add a field to a form template.
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
 * contact-form-7/add-form-field — composes the tag so the caller need not know the syntax.
 */
class Add_Form_Field extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'add-form-field';
	}

	protected function ability_label(): string {
		return __( 'Add Contact Form Field', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Add a field to a form, composing the Contact Form 7 tag and its label for you. Check contact-form-7/list-field-types first — the available types depend on which modules are active. The field is added at the end of the form by default, before or after the submit button depending on your template; use prepend to put it first. The new field name immediately becomes available as a mail tag, but it is not added to any mail template automatically.', 'acrossai-abilities-manager' );
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
			'type'     => array(
				'type'        => 'string',
				'description' => __( 'Field type, e.g. text, email, tel, textarea, select. Omit any trailing asterisk and use "required" instead.', 'acrossai-abilities-manager' ),
			),
			'name'     => array(
				'type'        => 'string',
				'description' => __( 'Field name, used as the mail tag. Letters, digits, hyphens and underscores.', 'acrossai-abilities-manager' ),
			),
			'label'    => array(
				'type'        => 'string',
				'description' => __( 'Visible label. Omit to emit the tag with no label wrapper.', 'acrossai-abilities-manager' ),
			),
			'required' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'options'  => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Tag options, e.g. class:wide, id:enquiry-phone or maxlength:40. Write each as key:value; a value containing spaces is quoted for you, and one with a space but no key is refused.', 'acrossai-abilities-manager' ),
			),
			'values'   => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Quoted values, e.g. the choices of a select or radio field.', 'acrossai-abilities-manager' ),
			),
			'prepend'  => array(
				'type'    => 'boolean',
				'default' => false,
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
		return array( 'form_id', 'type', 'name' );
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'contact-form-7/list-field-types',
				'reason' => 'The available field types depend on which Contact Form 7 modules are active on this site.',
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

		$type = sanitize_text_field( (string) ( $input['type'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );

		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $name ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'A field name must start with a letter and contain only letters, digits, hyphens and underscores.', 'acrossai-abilities-manager' )
			);
		}

		if ( ! Form_Tag_Repository::type_exists( $type ) ) {
			return new WP_Error(
				'unknown_field_type',
				sprintf(
					/* translators: %s: field type */
					__( '"%s" is not a field type on this site. Call contact-form-7/list-field-types.', 'acrossai-abilities-manager' ),
					$type
				)
			);
		}

		if ( Form_Tag_Repository::has_field( $form, $name ) ) {
			return new WP_Error(
				'field_exists',
				sprintf(
					/* translators: %s: field name */
					__( 'This form already has a field named "%s". Use contact-form-7/update-form-field.', 'acrossai-abilities-manager' ),
					$name
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
			! empty( $input['required'] ),
			isset( $input['options'] ) ? (array) $input['options'] : array(),
			isset( $input['values'] ) ? (array) $input['values'] : array()
		);

		$template = Form_Tag_Repository::append_field(
			(string) $form->prop( 'form' ),
			$tag,
			isset( $input['label'] ) ? sanitize_text_field( (string) $input['label'] ) : '',
			! empty( $input['prepend'] )
		);

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
				/* translators: 1: field name, 2: mail tag */
				__( 'Added "%1$s". It is available in mail templates as %2$s.', 'acrossai-abilities-manager' ),
				$name,
				'[' . $name . ']'
			),
		);
	}
}
