<?php
/**
 * Feature 119 - sends a real test message to prove delivery.
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
 * sends a real test message to prove delivery.
 *
 * @since 0.0.34
 */
final class Send_Test_Email extends Base_Email_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'email/send-test-email';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Send a Test Email', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Send a real test message through the configured mailer to prove delivery actually works. This is the only thing that answers the question: settings can be perfect and mail still not arrive, because a host may block the port, a provider may reject the sending address, or another plugin may take over. When it fails, the mail plugin own error text is returned rather than a generic refusal - that text is the whole point. A recipient is required and is never assumed, because this puts a real message in a real inbox.', 'acrossai-abilities-manager' );
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
		return array(
			'recipient' => array( 'type' => 'string', 'description' => __( 'Where to send the test message. Required, and never defaulted.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'recipient' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'recipient'           => array( 'type' => 'string' ),
			'mailer'              => array( 'type' => 'string' ),
			'accepted_by_mailer'  => array( 'type' => 'boolean' ),
			'domain_check_passed' => array( 'type' => 'boolean' ),
			'proves_delivery'     => array( 'type' => 'boolean' ),
			'notes'               => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This delivers a real email to the address given, which will arrive in somebody inbox. Pass confirm: true to send it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Email_Repository::send_test( (string) ( $input['recipient'] ?? '' ) );
	}
}
