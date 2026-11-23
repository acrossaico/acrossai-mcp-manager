<?php
/**
 * Feature 119 - explains whether this site can actually send email.
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
 * explains whether this site can actually send email.
 *
 * @since 0.0.34
 */
final class Get_Delivery_Status extends Base_Email_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'email/get-delivery-status';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Diagnose Email Delivery', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Work out whether this site can send email at all, and say why not. Checks the mailer in use, whether its credentials are stored, whether another plugin is also taking over email, and the last failure the mail plugin recorded. Run this first when form notifications, password resets or order emails are not arriving. It reads configuration only - to prove delivery end to end, follow it with email/send-test-email.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'diagnostics';
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
			'credentials_missing' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'conflicting_plugin' => array( 'type' => 'string' ),
			'all_conflicts' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'last_error' => array( 'type' => 'string' ),
			'looks_deliverable' => array( 'type' => 'boolean' ),
			'notes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
		return Email_Repository::status();
	}
}
