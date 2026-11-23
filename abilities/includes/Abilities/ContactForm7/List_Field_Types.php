<?php
/**
 * Feature 103 — the form-tag types available on this install.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Tag_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/list-field-types — install-specific, so it cannot be assumed.
 */
class List_Field_Types extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'list-field-types';
	}

	protected function ability_label(): string {
		return __( 'List Contact Form Field Types', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'List every form-tag type this site supports, with whether each accepts a field name and whether its value can appear in mail. Check here before adding a field: the set depends on which Contact Form 7 modules and add-ons are active, so a type that exists on one site may not exist on another. Appending an asterisk to a type makes the field required.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-fields';
	}

	protected function cf7_cap(): string {
		return 'read_contact_forms';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'types' => array( 'type' => 'array' ),
			'count' => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		unset( $input );

		$types = Form_Tag_Repository::types();

		return array(
			'types'   => $types,
			'count'   => count( $types ),
			'message' => sprintf(
				/* translators: %d: number of field types */
				_n( '%d field type available.', '%d field types available.', count( $types ), 'acrossai-abilities-manager' ),
				count( $types )
			),
		);
	}
}
