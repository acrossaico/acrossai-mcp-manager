<?php
/**
 * Feature 103 — find mail tags that resolve to nothing.
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
 * contact-form-7/validate-mail-tags — the check for CF7's quietest failure.
 */
class Validate_Mail_Tags extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'validate-mail-tags';
	}

	protected function ability_label(): string {
		return __( 'Validate Contact Form Mail Tags', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Check every mail tag used in a form\'s mail templates against the fields the form actually has, and report the ones that resolve to nothing. This is Contact Form 7\'s quietest failure: rename or remove a field and the notification keeps arriving, just with a blank where the value was. Nothing in the admin flags it, and the visitor still sees a success message. Run this after any change to a form\'s fields.', 'acrossai-abilities-manager' );
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
			'which'   => array(
				'type'        => 'string',
				'enum'        => array( 'mail', 'mail_2', 'both' ),
				'default'     => 'both',
				'description' => __( 'Which mail template to check.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id' => array( 'type' => 'integer' ),
			'valid'   => array( 'type' => 'boolean' ),
			'issues'  => array( 'type' => 'array' ),
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

		$which  = isset( $input['which'] ) ? (string) $input['which'] : 'both';
		$check  = 'both' === $which ? array( 'mail', 'mail_2' ) : array( $which );
		$issues = array();

		foreach ( $check as $template ) {
			$mail = (array) $form->prop( $template );

			// An inactive mail_2 is not a problem worth reporting: it sends nothing.
			if ( 'mail_2' === $template && empty( $mail['active'] ) ) {
				continue;
			}

			foreach ( array( 'subject', 'body', 'recipient', 'sender', 'additional_headers' ) as $field ) {
				$unresolved = Form_Tag_Repository::unresolvable_tags(
					$form,
					isset( $mail[ $field ] ) ? (string) $mail[ $field ] : ''
				);

				foreach ( $unresolved as $tag ) {
					$issues[] = array(
						'which' => $template,
						'field' => $field,
						'tag'   => '[' . $tag . ']',
					);
				}
			}
		}

		return array(
			'form_id' => (int) $form->id(),
			'valid'   => array() === $issues,
			'issues'  => $issues,
			'message' => array() === $issues
				? __( 'Every mail tag resolves to a field or a built-in tag.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of unresolved tags */
					_n(
						'%d mail tag resolves to nothing and will render blank.',
						'%d mail tags resolve to nothing and will render blank.',
						count( $issues ),
						'acrossai-abilities-manager'
					),
					count( $issues )
				),
		);
	}
}
