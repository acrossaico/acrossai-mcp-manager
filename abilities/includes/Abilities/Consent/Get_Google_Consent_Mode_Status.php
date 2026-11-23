<?php
/**
 * Feature 118 - reads Google Consent Mode status from the consent service.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Consent
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Consent;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Consent\Consent_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * reads Google Consent Mode status from the consent service.
 *
 * @since 0.0.34
 */
final class Get_Google_Consent_Mode_Status extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/get-google-consent-mode-status';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Reported Google Consent Mode Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read the Google Consent Mode status as the consent service reports it, which is what confirms the configuration is actually reaching Google. The local configuration is readable without an account through consent/get-google-consent-mode; this needs the site linked to a consent account.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'reporting';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'google_consent_mode_status' => array( 'type' => 'object', 'additionalProperties' => true ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_account(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function account_subject(): string {
		return __( 'The reported Google Consent Mode status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$class = '\\CookieYes\\Lite\\Admin\\Modules\\Settings\\Includes\\Controller';

		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_app_info' ) ) {
			return new WP_Error(
				'consent_feature_unavailable',
				__( 'This edition of the consent plugin does not expose a reported Google Consent Mode status.', 'acrossai-abilities-manager' )
			);
		}

		$info = (array) $class::get_instance()->get_app_info();

		return array(
			'google_consent_mode_status' => isset( $info['gcm'] ) ? (array) $info['gcm'] : Consent_Repository::gcm_state(),
		);
	}
}
