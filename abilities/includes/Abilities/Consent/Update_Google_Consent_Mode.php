<?php
/**
 * Feature 118 - changes the Google Consent Mode configuration.
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
 * changes the Google Consent Mode configuration.
 *
 * @since 0.0.34
 */
final class Update_Google_Consent_Mode extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/update-google-consent-mode';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Google Consent Mode', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Switch Google Consent Mode on or off and set its delivery options. The value is read back afterwards and a mismatch is reported rather than assumed saved.', 'acrossai-abilities-manager' );
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
		return array(
			'status' => array( 'type' => 'boolean', 'description' => __( 'Whether Google Consent Mode is active.', 'acrossai-abilities-manager' ) ),
			'wait_for_update' => array( 'type' => 'integer', 'description' => __( 'Milliseconds Google tags wait for a consent update before acting on the defaults.', 'acrossai-abilities-manager' ) ),
			'url_passthrough' => array( 'type' => 'boolean', 'description' => __( 'Whether to pass ad click information through URLs when consent is denied.', 'acrossai-abilities-manager' ) ),
			'ads_data_redaction' => array( 'type' => 'boolean', 'description' => __( 'Whether to redact ads data when advertising consent is denied.', 'acrossai-abilities-manager' ) ),
		);
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
			'google_consent_mode' => array( 'type' => 'object', 'additionalProperties' => true ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
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
		return Consent_Repository::update_gcm( $input );
	}
}
