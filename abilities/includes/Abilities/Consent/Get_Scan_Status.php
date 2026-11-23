<?php
/**
 * Feature 118 - reads the automated cookie scan status.
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
 * reads the automated cookie scan status.
 *
 * @since 0.0.34
 */
final class Get_Scan_Status extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/get-scan-status';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Cookie Scan Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read when this site was last scanned for cookies, whether that scan succeeded, and the plan limits that apply. Scanning is performed by the consent service and cannot be started from here, so this needs the site linked to a consent account. Without one, the declared cookie list is maintained by hand through consent/add-cookie.', 'acrossai-abilities-manager' );
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
			'scans' => array( 'type' => 'object', 'additionalProperties' => true ),
			'plan' => array( 'type' => 'object', 'additionalProperties' => true ),
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
		return __( 'The cookie scan status', 'acrossai-abilities-manager' );
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
				__( 'This edition of the consent plugin does not expose scan status.', 'acrossai-abilities-manager' )
			);
		}

		$info = (array) $class::get_instance()->get_app_info();

		return array(
			'scans' => isset( $info['scans'] ) ? (array) $info['scans'] : array(),
			'plan'  => isset( $info['plan'] ) ? (array) $info['plan'] : array(),
		);
	}
}
