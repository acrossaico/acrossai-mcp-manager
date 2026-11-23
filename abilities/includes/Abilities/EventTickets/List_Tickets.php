<?php
/**
 * Feature 110 — List Tickets.
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
 * tickets/list-tickets — List Tickets.
 */
final class List_Tickets extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/list-tickets';
	}

	protected function ability_label(): string {
		return __( 'List Tickets', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every ticket on a post, across all active providers, with the full capacity picture: the number, whether it is unlimited, which capacity mode it uses, and how many are sold, pending and still available. Capacity is not a single number in Event Tickets — a ticket can keep its own stock, draw from a shared event pool, draw from that pool up to a cap, or be unlimited — so all of it is reported rather than flattened.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'tickets';
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The event, page or other ticketed post.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'post_id' );
	}

	protected function output_properties(): array {
		return array(
			'tickets' => array( 'type' => 'array' ),
			'count' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'tickets/get-capacity-report',
				'reason' => __( 'The event-level shared pool, which a ticket in global or capped mode draws from.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'tickets/get-attendee-summary',
				'reason' => __( 'How many people are actually coming.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$post_id = (int) $input['post_id'];
		$post    = Ticket_Repository::require_ticketable( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$rows = Ticket_Repository::tickets_for( $post_id );

		return array(
			'tickets' => $rows,
			'count'   => count( $rows ),
			'message' => sprintf(
				/* translators: 1: number of tickets, 2: post ID */
				_n( '%1$d ticket on post %2$d.', '%1$d tickets on post %2$d.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows ),
				$post_id
			),
		);
	}
}
