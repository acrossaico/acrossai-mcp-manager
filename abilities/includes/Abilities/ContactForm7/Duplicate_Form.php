<?php
/**
 * Feature 103 — copy a contact form.
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
 * contact-form-7/duplicate-form — a copy under a new title.
 */
class Duplicate_Form extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'duplicate-form';
	}

	protected function ability_label(): string {
		return __( 'Duplicate Contact Form', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Copy a form, including its template, both mail templates, messages and settings, under a new title. Useful as a safety step before a large edit, and as the fastest way to make a variant of a working form rather than rebuilding it.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-forms';
	}

	protected function cf7_cap(): string {
		return 'edit_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'form_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'title'   => array(
				'type'        => 'string',
				'description' => __( 'Title for the copy. Defaults to the original plus "_copy".', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form'      => array( 'type' => 'object' ),
			'source_id' => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array( 'form_id' );
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	protected function run( array $input ) {
		$source_id = absint( $input['form_id'] ?? 0 );
		$source    = Form_Repository::get( $source_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$new_id = Form_Repository::duplicate(
			$source,
			isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : ''
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$copy = Form_Repository::get( $new_id );

		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		return array(
			'form'      => Form_Repository::describe( $copy ),
			'source_id' => $source_id,
			'message'   => sprintf(
				/* translators: 1: new title, 2: new id */
				__( 'Duplicated as "%1$s" (id %2$d).', 'acrossai-abilities-manager' ),
				$copy->title(),
				$new_id
			),
		);
	}
}
