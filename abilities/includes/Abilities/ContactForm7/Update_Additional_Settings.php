<?php
/**
 * Feature 103 — write whitelisted additional settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Contact_Form_7_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Form_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * contact-form-7/update-additional-settings — a whitelist, deliberately.
 *
 * CF7 does not sanitise this property at all — only trim() — and the values are behaviour switches
 * rather than cosmetics. A free-text passthrough would let a caller disable a site's contact route by
 * accident, so only four keys are writable and the two that change delivery need confirmation.
 */
class Update_Additional_Settings extends Base_Contact_Form_7_Ability {

	protected function slug(): string {
		return 'update-additional-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Contact Form Additional Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Change a form\'s behaviour switches. Only four are writable: demo_mode, skip_mail, subscribers_only and acceptance_as_validation. Contact Form 7 does not validate this field at all, so anything else is refused rather than written. Turning on demo_mode or skip_mail stops the form delivering mail while it continues to report success to visitors, so both require confirm: true.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'cf7-settings';
	}

	protected function cf7_cap(): string {
		return 'edit_contact_forms';
	}

	protected function input_properties(): array {
		return array(
			'form_id'  => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'settings' => array(
				'type'        => 'object',
				'description' => __( 'Setting key => value, e.g. { "skip_mail": "off" }. Pass an empty string to remove a setting.', 'acrossai-abilities-manager' ),
			),
			'confirm'  => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Required when changing demo_mode or skip_mail.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'form_id'  => array( 'type' => 'integer' ),
			'settings' => array( 'type' => 'array' ),
			'updated'  => array( 'type' => 'array' ),
			'raw'      => array( 'type' => 'string' ),
		);
	}

	protected function required_input(): array {
		return array( 'form_id', 'settings' );
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
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

		$patch = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( array() === $patch ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one setting to change.', 'acrossai-abilities-manager' ) );
		}

		$current = Form_Repository::parse_additional_settings( $form );
		$updated = array();

		foreach ( $patch as $key => $value ) {
			$key = (string) $key;

			if ( ! array_key_exists( $key, Form_Repository::WRITABLE_SETTINGS ) ) {
				return new WP_Error(
					'setting_not_writable',
					sprintf(
						/* translators: 1: setting key, 2: comma-separated writable keys */
						__( '"%1$s" is not a writable setting. This ability writes only: %2$s.', 'acrossai-abilities-manager' ),
						$key,
						implode( ', ', array_keys( Form_Repository::WRITABLE_SETTINGS ) )
					)
				);
			}

			$value = sanitize_text_field( (string) $value );

			if ( ( $current[ $key ] ?? '' ) === $value ) {
				continue;
			}

			if ( Form_Repository::WRITABLE_SETTINGS[ $key ] && empty( $input['confirm'] ) ) {
				return new WP_Error(
					'confirmation_required',
					sprintf(
						/* translators: %s: setting key */
						__( 'Changing "%s" alters whether this form delivers mail, and the form keeps reporting success either way. Pass confirm: true to proceed.', 'acrossai-abilities-manager' ),
						$key
					)
				);
			}

			if ( '' === $value ) {
				unset( $current[ $key ] );
			} else {
				$current[ $key ] = $value;
			}

			$updated[] = $key;
		}

		if ( array() === $updated ) {
			return array(
				'form_id'  => $form_id,
				'settings' => Form_Repository::describe_additional_settings( $form ),
				'updated'  => array(),
				'raw'      => (string) $form->prop( 'additional_settings' ),
				'message'  => __( 'No change — the settings already had those values.', 'acrossai-abilities-manager' ),
			);
		}

		$saved = Form_Repository::save(
			array(
				'id'                  => $form_id,
				'additional_settings' => Form_Repository::render_additional_settings( $current ),
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$fresh = Form_Repository::get( $form_id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		return array(
			'form_id'  => $form_id,
			'settings' => Form_Repository::describe_additional_settings( $fresh ),
			'updated'  => $updated,
			'raw'      => (string) $fresh->prop( 'additional_settings' ),
			'message'  => sprintf(
				/* translators: %s: comma-separated setting keys */
				__( 'Updated: %s.', 'acrossai-abilities-manager' ),
				implode( ', ', $updated )
			),
		);
	}
}
