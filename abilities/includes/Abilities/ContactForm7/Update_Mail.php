<?php
/**
 * Feature 103 — patch a mail template.
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
 * contact-form-7/update-mail — merges into the existing template.
 *
 * Changing `recipient` requires confirm: true. That one field decides where every submission of the
 * form is delivered, and CF7 only trims it — a wrong value silently routes a site's enquiries
 * somewhere else, with the form still reporting success to the visitor.
 */
class Update_Mail extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'update-mail';
	}

	protected function ability_label(): string {
		return __( 'Update Contact Form Mail Template', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Change fields of one mail template, merging into what is already there — fields you omit keep their current values. Use contact-form-7/list-mail-tags to see which tags this form provides before writing a body. Changing the recipient requires confirm: true, because that decides where every submission is delivered and a wrong address fails silently: the visitor still sees a success message.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-mail';
	}

	protected function cf7_cap(): string {
		return 'edit_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'form_id'            => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'which'              => array(
				'type'    => 'string',
				'enum'    => array( 'mail', 'mail_2' ),
				'default' => 'mail',
			),
			'subject'            => array( 'type' => 'string' ),
			'sender'             => array( 'type' => 'string' ),
			'recipient'          => array(
				'type'        => 'string',
				'description' => __( 'Where submissions are delivered. Changing this requires confirm: true.', 'acrossai-abilities-manager' ),
			),
			'body'               => array( 'type' => 'string' ),
			'additional_headers' => array( 'type' => 'string' ),
			'use_html'           => array( 'type' => 'boolean' ),
			'exclude_blank'      => array( 'type' => 'boolean' ),
			'confirm'            => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Required only when changing the recipient.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id'          => array( 'type' => 'integer' ),
			'which'            => array( 'type' => 'string' ),
			'mail'             => array( 'type' => 'object' ),
			'updated'          => array( 'type' => 'array' ),
			'unresolved_tags'  => array( 'type' => 'array' ),
			'sanitised'        => array(
				'type'        => 'array',
				'description' => 'Fields whose content was altered by sanitising before it was stored. Read the returned mail to see what was kept.',
			),
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
				'slug'   => 'contact-form-7/list-mail-tags',
				'reason' => 'Shows which tags this form actually provides, so the body does not reference a field that does not exist.',
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

		$which     = isset( $input['which'] ) && 'mail_2' === $input['which'] ? 'mail_2' : 'mail';
		$mail      = (array) $form->prop( $which );
		$updated   = array();
		$sanitised = array();

		foreach ( array( 'subject', 'sender', 'recipient', 'body', 'additional_headers' ) as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}

			$value = (string) $input[ $field ];

			if ( 'recipient' === $field
				&& $value !== (string) ( $mail['recipient'] ?? '' )
				&& empty( $input['confirm'] ) ) {
				return new WP_Error(
					'confirmation_required',
					__( 'Changing the recipient redirects every submission of this form. Pass confirm: true to proceed.', 'acrossai-abilities-manager' )
				);
			}

			if ( 'body' === $field ) {
				// Sanitising happens in the repository, so without this the caller
				// is told the write succeeded and never learns that part of what it
				// sent was dropped. Silence is the bug; the stripping is deliberate.
				$clean = Form_Repository::sanitise_mail_body( $value );

				if ( $clean !== $value ) {
					$sanitised[] = $field;
				}

				$value = $clean;
			}

			$mail[ $field ] = $value;
			$updated[]      = $field;
		}

		foreach ( array( 'use_html', 'exclude_blank' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$mail[ $flag ] = (bool) $input[ $flag ];
				$updated[]     = $flag;
			}
		}

		if ( array() === $updated ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one mail field to change.', 'acrossai-abilities-manager' ) );
		}

		$saved = Form_Repository::save(
			array(
				'id'   => $form_id,
				$which => $mail,
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$fresh = Form_Repository::get( $form_id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$stored = (array) $fresh->prop( $which );

		return array(
			'form_id'         => $form_id,
			'which'           => $which,
			'mail'            => $stored,
			'updated'         => $updated,
			'unresolved_tags' => Form_Tag_Repository::unresolvable_tags(
				$fresh,
				(string) ( $stored['body'] ?? '' ) . ' ' . (string) ( $stored['subject'] ?? '' )
			),
			'sanitised'       => $sanitised,
			'message'         => array() === $sanitised
				? sprintf(
					/* translators: 1: mail or mail_2, 2: comma-separated field names */
					__( 'Updated %1$s: %2$s.', 'acrossai-abilities-manager' ),
					$which,
					implode( ', ', $updated )
				)
				: sprintf(
					/* translators: 1: mail or mail_2, 2: comma-separated field names, 3: comma-separated field names */
					__( 'Updated %1$s: %2$s. Sanitising changed what was stored for: %3$s — read the returned mail before assuming it says what you sent.', 'acrossai-abilities-manager' ),
					$which,
					implode( ', ', $updated ),
					implode( ', ', $sanitised )
				),
		);
	}
}
