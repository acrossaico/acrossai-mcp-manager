<?php
/**
 * Feature 110 — Get Ticket.
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
 * tickets/get-ticket — Get Ticket.
 */
final class Get_Ticket extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/get-ticket';
	}

	protected function ability_label(): string {
		return __( 'Get Ticket', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'One ticket with its price, sale window, provider and full capacity model.',
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
			'ticket' => array( 'type' => 'object' ),
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
		$ticket_id = (int) $input['ticket_id'];
		$ticket    = Ticket_Repository::require_ticket( $ticket_id );

		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$row = Ticket_Repository::describe_ticket( $ticket );

		return array(
			'ticket'  => $row,
			'message' => sprintf(
				/* translators: 1: ticket name, 2: available count */
				__( '"%1$s" — %2$s.', 'acrossai-abilities-manager' ),
				$row['name'],
				$row['unlimited'] ? __( 'unlimited', 'acrossai-abilities-manager' ) : sprintf( /* translators: %d: count */ __( '%d available', 'acrossai-abilities-manager' ), $row['available'] )
			),
		);
	}
}
