<?php
/**
 * Feature 103 — replace a form template wholesale.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Contact_Form_7_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/update-form-template — the escape hatch.
 */
class Update_Form_Template extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'update-form-template';
	}

	protected function ability_label(): string {
		return __( 'Update Contact Form Template', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Replace a form\'s entire template markup. This is the escape hatch for layout changes the field abilities cannot express — for anything field-shaped prefer contact-form-7/add-form-field, update-form-field or remove-form-field, which leave the rest of the markup untouched. Replacing the template drops any field you do not carry over, which silently breaks mail tags that referenced it, so run contact-form-7/validate-mail-tags and validate-form-config afterwards.', 'acrossai-abilities-manager' );
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
			'template' => array(
				'type'        => 'string',
				'description' => __( 'The complete new template.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id'       => array( 'type' => 'integer' ),
			'template'      => array( 'type' => 'string' ),
			'fields'        => array( 'type' => 'array' ),
			'config_errors' => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array( 'form_id', 'template' );
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
				'slug'   => 'contact-form-7/validate-mail-tags',
				'reason' => 'Replacing a template can drop a field the mail body still references, which fails silently.',
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

		$saved = Form_Repository::save(
			array(
				'id'   => $form_id,
				'form' => (string) ( $input['template'] ?? '' ),
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$fresh = Form_Repository::get( $form_id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		return array(
			'form_id'       => $form_id,
			'template'      => (string) $fresh->prop( 'form' ),
			'fields'        => \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Tag_Repository::tags( $fresh ),
			'config_errors' => Form_Repository::config_errors( $fresh ),
			'message'       => __( 'Form template replaced.', 'acrossai-abilities-manager' ),
		);
	}
}
