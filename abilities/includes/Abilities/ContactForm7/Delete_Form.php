<?php
/**
 * Feature 103 — permanently delete a contact form.
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
 * contact-form-7/delete-form — irreversible.
 *
 * CF7's delete() calls wp_delete_post( $id, true ): it bypasses the trash entirely, so there is no
 * "restore from trash" afterwards. Hence the confirm gate and the destructive annotation.
 */
class Delete_Form extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'delete-form';
	}

	protected function ability_label(): string {
		return __( 'Delete Contact Form', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Permanently delete a contact form. Contact Form 7 bypasses the trash, so the form, its mail templates and its settings are unrecoverable. Any page still containing its shortcode will show nothing. Prefer contact-form-7/duplicate-form as a backup before destructive edits. Requires confirm: true.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-forms';
	}

	protected function cf7_cap(): string {
		return 'delete_contact_form';
	}

	protected function requires_confirmation(): bool {
		return true;
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
			'title'   => array( 'type' => 'string' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Contact_Form_7_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'form_id' );
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
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

		$title   = (string) $form->title();
		$deleted = Form_Repository::delete( $form );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return array(
			'form_id' => $form_id,
			'title'   => $title,
			'message' => sprintf(
				/* translators: %s: form title */
				__( 'Permanently deleted "%s".', 'acrossai-abilities-manager' ),
				$title
			),
		);
	}
}
