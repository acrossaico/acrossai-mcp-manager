<?php
/**
 * Feature 103 — read a form's additional settings.
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
 * contact-form-7/get-additional-settings — parsed, with each switch explained.
 */
class Get_Additional_Settings extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'get-additional-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Contact Form Additional Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return a form\'s additional settings as parsed key/value pairs, flagging which are behaviour switches this suite can write. These are easy to miss and consequential — skip_mail turns off all mail for a form while the form still reports success to visitors, and demo_mode stops submissions being processed at all. Check here first when a form appears to work but nothing arrives.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-settings';
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
			'form_id'  => array( 'type' => 'integer' ),
			'settings' => array( 'type' => 'array' ),
			'raw'      => array( 'type' => 'string' ),
			'writable' => array( 'type' => 'array' ),
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

		$rows = Form_Repository::describe_additional_settings( $form );

		return array(
			'form_id'  => (int) $form->id(),
			'settings' => $rows,
			'raw'      => (string) $form->prop( 'additional_settings' ),
			'writable' => array_keys( Form_Repository::WRITABLE_SETTINGS ),
			'message'  => sprintf(
				/* translators: %d: number of settings */
				_n( '%d additional setting.', '%d additional settings.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
