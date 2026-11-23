<?php
/**
 * Feature 118 - reports whether the rendered banner is current.
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
 * reports whether the rendered banner is current.
 *
 * @since 0.0.34
 */
final class Get_Banner_Status extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/get-banner-status';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Banner Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report the state of the banner a visitor actually receives: whether it has been rendered, its size per language, and whether it is waiting to be rebuilt. Worth checking after anything has written the consent tables directly, because the banner is cached HTML and a direct write leaves it showing the previous version.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'banner';
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
			'languages' => array( 'type' => 'object', 'additionalProperties' => true ),
			'rendered' => array( 'type' => 'boolean' ),
			'pending_rebuild' => array( 'type' => 'boolean' ),
			'banner_enabled' => array( 'type' => 'boolean' ),
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
		$state = Consent_Repository::template_state();

		$snapshot                = Consent_Repository::settings_snapshot();
		$state['banner_enabled'] = (bool) $snapshot['banner_enabled'];

		return $state;
	}
}
