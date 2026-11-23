<?php
/**
 * Feature 119 - reads how this site sends email.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Email
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Email;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Email\Email_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * reads how this site sends email.
 *
 * @since 0.0.34
 */
final class Get_Delivery_Settings extends Base_Email_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'email/get-delivery-settings';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Email Delivery Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read how this site is configured to send email: which mailer it uses, the address and name it sends from, and whether those are forced over what individual plugins ask for. Passwords and API keys are never returned - each is reported only as set or not set, and everything withheld is listed so you can tell an empty setting from a hidden one.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'delivery';
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
			'mailer' => array( 'type' => 'string' ),
			'from_email' => array( 'type' => 'string' ),
			'from_name' => array( 'type' => 'string' ),
			'from_email_force' => array( 'type' => 'boolean' ),
			'from_name_force' => array( 'type' => 'boolean' ),
			'return_path' => array( 'type' => 'boolean' ),
			'credentials_set' => array( 'type' => 'object', 'additionalProperties' => true ),
			'withheld' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'configured_groups' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
		return Email_Repository::settings();
	}
}
