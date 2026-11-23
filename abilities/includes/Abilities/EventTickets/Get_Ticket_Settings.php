<?php
/**
 * Feature 110 — Get Ticket Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventTickets;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets\Ticket_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * tickets/get-ticket-settings — Get Ticket Settings.
 */
final class Get_Ticket_Settings extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/get-ticket-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Ticket Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The ticketing settings, as rows. Payment gateway credentials are not readable here at all: Tickets Commerce keeps its Stripe, PayPal and Square tokens, webhook signing keys and verifiers in separate option rows rather than the shared settings blob, and this reads only the blob. Anything credential-shaped that does appear is returned with a null value and a redacted flag.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'setup';
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array(  );
	}

	protected function output_properties(): array {
		return array(
			'settings' => array( 'type' => 'array' ),
			'count' => array( 'type' => 'integer' ),
			'redacted' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$rows     = Ticket_Repository::settings();
		$redacted = 0;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['redacted'] ) ) {
				++$redacted;
			}
		}

		return array(
			'settings' => $rows,
			'count'    => count( $rows ),
			'redacted' => $redacted,
			'message'  => sprintf(
				/* translators: 1: number of settings, 2: number redacted */
				__( '%1$d ticketing settings, %2$d redacted. Gateway credentials live outside this blob and are never read.', 'acrossai-abilities-manager' ),
				count( $rows ),
				$redacted
			),
		);
	}
}
