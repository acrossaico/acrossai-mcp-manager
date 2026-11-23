<?php
/**
 * Feature 110 — Delete Ticket.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventTickets;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets\Ticket_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * tickets/delete-ticket — Delete Ticket.
 */
final class Delete_Ticket extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/delete-ticket';
	}

	protected function ability_label(): string {
		return __( 'Delete Ticket', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Remove a ticket. Event Tickets deletes these permanently — there is no trash for tickets — and stamps the ticket name onto anyone who already bought one so their record is not orphaned. Confirm required.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'tickets';
	}

	protected function input_properties(): array {
		return array(
			'ticket_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The ticket ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'ticket_id' );
	}

	protected function output_properties(): array {
		return array(
			'ticket_id' => array( 'type' => 'integer' ),
			'deleted' => array( 'type' => 'boolean' ),
			'attendees_affected' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __( 'Deleting a ticket is permanent — Event Tickets does not trash them — and anyone who already bought one keeps an attendee record pointing at a ticket that no longer exists. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	protected function run( array $input ) {
		$ticket_id = (int) $input['ticket_id'];
		$ticket    = Ticket_Repository::require_ticket( $ticket_id );

		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$row      = Ticket_Repository::describe_ticket( $ticket );
		$affected = (int) $row['sold'] + (int) $row['pending'];
		$deleted  = Ticket_Repository::delete_ticket( $ticket_id );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return array(
			'ticket_id'          => $ticket_id,
			'deleted'            => true,
			'attendees_affected' => $affected,
			'message'            => sprintf(
				/* translators: 1: ticket name, 2: number of attendees */
				__( 'Deleted "%1$s". %2$d attendee record(s) referenced it.', 'acrossai-abilities-manager' ),
				$row['name'],
				$affected
			),
		);
	}
}
