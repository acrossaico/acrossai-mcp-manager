<?php
/**
 * Feature 118 - reads the consent configuration.
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
 * reads the consent configuration.
 *
 * @since 0.0.34
 */
final class Get_Consent_Settings extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/get-consent-settings';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Consent Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read the consent configuration: whether the banner is switched on, the languages it is offered in, whether consent records are being kept, and whether this site is linked to a consent account. Credentials stored alongside these settings are never returned, and anything withheld is listed so you can tell an empty setting from a hidden one.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'settings';
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
			'connected' => array( 'type' => 'boolean' ),
			'banner_enabled' => array( 'type' => 'boolean' ),
			'consent_log_status' => array( 'type' => 'boolean' ),
			'default_language' => array( 'type' => 'string' ),
			'selected_languages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'plan' => array( 'type' => 'object', 'additionalProperties' => true ),
			'redacted' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'onboarding_step' => array( 'type' => 'integer' ),
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
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Consent_Repository::settings_snapshot();
	}
}
