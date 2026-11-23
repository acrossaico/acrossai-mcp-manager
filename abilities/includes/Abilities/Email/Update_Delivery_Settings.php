<?php
/**
 * Feature 119 - changes the sending address and name.
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
 * changes the sending address and name.
 *
 * @since 0.0.34
 */
final class Update_Delivery_Settings extends Base_Email_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'email/update-delivery-settings';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Email Delivery Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Change the address and name this site sends email from, and whether those are forced over what individual plugins ask for. Deliberately narrow: the mailer itself cannot be switched here, because changing it without the matching credentials stops all email on the site including password resets, and no credential can be written here at all. Use the mail plugin own settings screen for those.', 'acrossai-abilities-manager' );
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
		return array(
			'from_email' => array( 'type' => 'string', 'description' => __( 'Address the site sends from.', 'acrossai-abilities-manager' ) ),
			'from_name' => array( 'type' => 'string', 'description' => __( 'Name the site sends as.', 'acrossai-abilities-manager' ) ),
			'from_email_force' => array( 'type' => 'boolean', 'description' => __( 'Force this address over what individual plugins ask for.', 'acrossai-abilities-manager' ) ),
			'from_name_force' => array( 'type' => 'boolean', 'description' => __( 'Force this name over what individual plugins ask for.', 'acrossai-abilities-manager' ) ),
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
			'settings' => array( 'type' => 'object', 'additionalProperties' => true ),
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
		return Email_Repository::update_settings( $input );
	}
}
