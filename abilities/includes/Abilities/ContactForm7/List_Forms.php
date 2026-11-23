<?php
/**
 * Feature 103 — enumerate the site's contact forms.
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
 * contact-form-7/list-forms — every form, with enough detail to choose one.
 */
class List_Forms extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'list-forms';
	}

	protected function ability_label(): string {
		return __( 'List Contact Forms', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'List every Contact Form 7 form with its id, title, shortcode, field count and number of configuration errors. Start here: the shortcode is what embeds a form on a page, and a non-zero error count means Contact Form 7 itself considers the form misconfigured — run contact-form-7/validate-form-config on it for the detail.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-forms';
	}

	protected function cf7_cap(): string {
		return 'read_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'search' => array(
				'type'        => 'string',
				'description' => __( 'Optional title substring filter.', 'acrossai-abilities-manager' ),
			),
			'limit'  => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 200,
				'default' => 100,
			),
			'offset' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'forms' => array( 'type' => 'array' ),
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
		$forms = Form_Repository::list_all(
			isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
			isset( $input['limit'] ) ? absint( $input['limit'] ) : 100,
			isset( $input['offset'] ) ? absint( $input['offset'] ) : 0
		);

		return array(
			'forms'   => $forms,
			'count'   => count( $forms ),
			'message' => sprintf(
				/* translators: %d: number of forms */
				_n( '%d contact form.', '%d contact forms.', count( $forms ), 'acrossai-abilities-manager' ),
				count( $forms )
			),
		);
	}
}
