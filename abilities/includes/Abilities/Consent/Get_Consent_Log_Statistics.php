<?php
/**
 * Feature 118 - reads consent statistics from the consent service.
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
 * reads consent statistics from the consent service.
 *
 * @since 0.0.34
 */
final class Get_Consent_Log_Statistics extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/get-consent-log-statistics';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Consent Statistics', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read how many visitors accepted, rejected or partly accepted cookies. These records are kept by the consent service rather than on this site - there is no local table for them - so this needs the site linked to a consent account.', 'acrossai-abilities-manager' );
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
			'statistics' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
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
		return __( 'Consent statistics', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$class = '\\CookieYes\\Lite\\Admin\\Modules\\Consentlogs\\Includes\\Controller';

		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_statistics' ) ) {
			return new WP_Error(
				'consent_feature_unavailable',
				__( 'This edition of the consent plugin does not expose consent statistics.', 'acrossai-abilities-manager' )
			);
		}

		return array( 'statistics' => array_values( (array) $class::get_instance()->get_statistics() ) );
	}
}
