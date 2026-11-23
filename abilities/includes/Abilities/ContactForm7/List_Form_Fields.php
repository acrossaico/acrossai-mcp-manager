<?php
/**
 * Feature 103 — the fields in a form template, parsed.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Tag_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/list-form-fields — structured fields instead of raw markup.
 */
class List_Form_Fields extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'list-form-fields';
	}

	protected function ability_label(): string {
		return __( 'List Contact Form Fields', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Parse a form template and return its fields as structured data: name, type, whether it is required, and its options and choices. Use this rather than reading the raw template when you need to reason about the fields — it is also the list of names available as mail tags. Entries with an empty name are controls such as the submit button.', 'acrossai-abilities-manager' );
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
			'form_id' => array( 'type' => 'integer' ),
			'fields'  => array( 'type' => 'array' ),
			'count'   => array( 'type' => 'integer' ),
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

		$fields = Form_Tag_Repository::tags( $form );

		return array(
			'form_id' => (int) $form->id(),
			'fields'  => $fields,
			'count'   => count( $fields ),
			'message' => sprintf(
				/* translators: %d: number of fields */
				_n( '%d field.', '%d fields.', count( $fields ), 'acrossai-abilities-manager' ),
				count( $fields )
			),
		);
	}
}
