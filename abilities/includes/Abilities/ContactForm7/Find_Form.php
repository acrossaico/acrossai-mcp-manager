<?php
/**
 * Feature 103 — resolve a form by title or hash.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/find-form — look a form up the way a human names it.
 */
class Find_Form extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'find-form';
	}

	protected function ability_label(): string {
		return __( 'Find Contact Form', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Find a form by its exact title or by the hash used in its shortcode, and return its summary. Use this when you know what a form is called but not its id — for example when a person says "the contact form" or pastes a shortcode.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-forms';
	}

	protected function cf7_cap(): string {
		return 'read_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'title' => array(
				'type'        => 'string',
				'description' => __( 'Exact form title.', 'acrossai-abilities-manager' ),
			),
			'hash'  => array(
				'type'        => 'string',
				'description' => __( 'Form hash, as it appears in the shortcode.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form' => array( 'type' => array( 'object', 'null' ) ),
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
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$hash  = isset( $input['hash'] ) ? sanitize_text_field( (string) $input['hash'] ) : '';

		if ( '' === $title && '' === $hash ) {
			return new WP_Error( 'invalid_input', __( 'Provide either a title or a hash.', 'acrossai-abilities-manager' ) );
		}

		$found = null;

		foreach ( Form_Repository::list_all( '', 500, 0 ) as $summary ) {
			if ( ( '' !== $title && $summary['title'] === $title )
				|| ( '' !== $hash && 0 === strpos( $summary['hash'], $hash ) ) ) {
				$found = $summary;
				break;
			}
		}

		if ( null === $found ) {
			return array(
				'form'    => null,
				'message' => __( 'No form matched.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'form'    => $found,
			'message' => sprintf(
				/* translators: 1: form title, 2: form id */
				__( 'Found "%1$s" (id %2$d).', 'acrossai-abilities-manager' ),
				$found['title'],
				$found['form_id']
			),
		);
	}
}
