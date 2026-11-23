<?php
/**
 * Feature 103 — the mail tags a form provides.
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
 * contact-form-7/list-mail-tags — what a mail body may reference.
 */
class List_Mail_Tags extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'list-mail-tags';
	}

	protected function ability_label(): string {
		return __( 'List Contact Form Mail Tags', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'List every mail tag a form makes available: one per named field, plus Contact Form 7\'s built-in tags for site and submission context such as the date, the submitting URL and the visitor IP. Read this before writing a mail body — a tag that matches nothing here is not an error, it simply renders as nothing.', 'acrossai-abilities-manager' );
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
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id'     => array( 'type' => 'integer' ),
			'from_fields' => array( 'type' => 'array' ),
			'special'     => array( 'type' => 'array' ),
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

		$tags = Form_Tag_Repository::mail_tags( $form );

		return array(
			'form_id'     => (int) $form->id(),
			'from_fields' => $tags['from_fields'],
			'special'     => $tags['special'],
			'message'     => sprintf(
				/* translators: %d: number of field tags */
				_n(
					'%d tag from this form\'s fields, plus the built-in tags.',
					'%d tags from this form\'s fields, plus the built-in tags.',
					count( $tags['from_fields'] ),
					'acrossai-abilities-manager'
				),
				count( $tags['from_fields'] )
			),
		);
	}
}
