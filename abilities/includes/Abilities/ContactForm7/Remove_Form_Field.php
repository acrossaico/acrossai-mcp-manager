<?php
/**
 * Feature 103 — remove a field from a form template.
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

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/remove-form-field — takes the wrapping label with it.
 */
class Remove_Form_Field extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'remove-form-field';
	}

	protected function ability_label(): string {
		return __( 'Remove Contact Form Field', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Remove a field from a form, along with the label wrapping it when that label held nothing else. Requires confirm: true, because a mail template still referencing the removed field keeps sending with a blank line where the value used to be and nothing reports it. The response lists any mail tags left dangling — fix them with contact-form-7/update-mail.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-fields';
	}

	protected function cf7_cap(): string {
		return 'edit_contact_forms';
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
			'name'    => array( 'type' => 'string' ),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id'           => array( 'type' => 'integer' ),
			'removed'           => array( 'type' => 'string' ),
			'template'          => array( 'type' => 'string' ),
			'dangling_mail_tags' => array( 'type' => 'array' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Contact_Form_7_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'form_id', 'name' );
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

		$name     = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$template = Form_Tag_Repository::remove_field( (string) $form->prop( 'form' ), $name );

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

		$dangling = array();

		foreach ( array( 'mail', 'mail_2' ) as $which ) {
			$mail = (array) $fresh->prop( $which );
			$body = isset( $mail['body'] ) ? (string) $mail['body'] : '';

			foreach ( Form_Tag_Repository::unresolvable_tags( $fresh, $body ) as $tag ) {
				if ( ! in_array( $tag, $dangling, true ) ) {
					$dangling[] = $tag;
				}
			}
		}

		return array(
			'form_id'            => $form_id,
			'removed'            => $name,
			'template'           => (string) $fresh->prop( 'form' ),
			'dangling_mail_tags' => $dangling,
			'message'            => array() === $dangling
				? sprintf(
					/* translators: %s: field name */
					__( 'Removed field "%s".', 'acrossai-abilities-manager' ),
					$name
				)
				: sprintf(
					/* translators: 1: field name, 2: comma-separated tag names */
					__( 'Removed field "%1$s". Mail templates still reference: %2$s — those tags now resolve to nothing.', 'acrossai-abilities-manager' ),
					$name,
					implode( ', ', $dangling )
				),
		);
	}
}
