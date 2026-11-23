<?php
/**
 * Feature 110 — Update Ticket.
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
 * tickets/update-ticket — Update Ticket.
 */
final class Update_Ticket extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/update-ticket';
	}

	protected function ability_label(): string {
		return __( 'Update Ticket', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change a ticket\'s name, description, price or sale window. Capacity changes are accepted here too and go through the plugin\'s own handling, which subtracts what is already sold and pending — writing the capacity meta directly instead would double-count those sales. A ticket cannot be moved to a different post.',
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
			'name' => array(
				'type'        => 'string',
				'description' => __( 'The ticket name shown to buyers.', 'acrossai-abilities-manager' ),
			),
			'description' => array(
				'type'        => 'string',
				'description' => __( 'Ticket description.', 'acrossai-abilities-manager' ),
			),
			'price' => array(
				'type'        => 'string',
				'description' => __( 'Price. Use "0" or omit for a free ticket.', 'acrossai-abilities-manager' ),
			),
			'capacity' => array(
				'type'        => 'integer',
				'description' => __( 'How many can be sold. Use -1 for unlimited. On an update this goes through the plugin\'s own capacity handling, which reconciles stock against what has already been sold.', 'acrossai-abilities-manager' ),
			),
			'capacity_mode' => array(
				'type'        => 'string',
				'enum'        => array( 'own', 'global', 'capped', 'unlimited' ),
				'description' => __( 'own keeps independent stock; global draws from the event pool; capped draws from the pool up to a limit; unlimited has no cap.', 'acrossai-abilities-manager' ),
			),
			'shared_cap' => array(
				'type'        => 'integer',
				'description' => __( 'The limit when capacity_mode is capped.', 'acrossai-abilities-manager' ),
			),
			'start_date' => array(
				'type'        => 'string',
				'description' => __( 'When the ticket goes on sale, e.g. "2026-11-01".', 'acrossai-abilities-manager' ),
			),
			'end_date' => array(
				'type'        => 'string',
				'description' => __( 'When sales close.', 'acrossai-abilities-manager' ),
			),
			'provider' => array(
				'type'        => 'string',
				'description' => __( 'Provider class to create through. Omit for the site default. Use tickets/list-ticket-providers to see what is active.', 'acrossai-abilities-manager' ),
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
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$ticket_id = (int) $input['ticket_id'];
		$existing  = Ticket_Repository::require_ticket( $ticket_id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$current  = Ticket_Repository::describe_ticket( $existing );
		$post_id  = (int) $current['event_id'];

		if ( ! $post_id ) {
			return new WP_Error( 'ticket_unattached', __( 'That ticket is not attached to a post, so it cannot be updated.', 'acrossai-abilities-manager' ) );
		}

		$data = array();

		if ( isset( $input['name'] ) ) {
			$data['ticket_name'] = (string) $input['name'];
		}

		if ( isset( $input['description'] ) ) {
			$data['ticket_description'] = (string) $input['description'];
		}

		if ( isset( $input['price'] ) ) {
			$data['ticket_price'] = (string) $input['price'];
		}

		if ( isset( $input['start_date'] ) ) {
			$data['ticket_start_date'] = (string) $input['start_date'];
		}

		if ( isset( $input['end_date'] ) ) {
			$data['ticket_end_date'] = (string) $input['end_date'];
		}

		$capacity = array();

		if ( isset( $input['capacity_mode'] ) ) {
			// Event Tickets stores unlimited as an empty mode, not the literal word.
			$capacity['mode'] = 'unlimited' === $input['capacity_mode'] ? '' : (string) $input['capacity_mode'];
		}

		if ( isset( $input['capacity'] ) ) {
			$capacity['capacity'] = (int) $input['capacity'];
		}

		if ( isset( $input['shared_cap'] ) ) {
			$capacity['event_capacity'] = (int) $input['shared_cap'];
		}

		/*
		 * Decide "did the caller ask for anything?" BEFORE the capacity block is filled in below.
		 * That block is always present so a partial update does not zero the capacity, which means
		 * $data is never empty by the time we reach the write — an earlier version checked it there
		 * and silently accepted a call that changed nothing.
		 */
		if ( array() === $data && array() === $capacity ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one field to change.', 'acrossai-abilities-manager' ) );
		}

		/*
		 * Always send a capacity block, even when the caller did not ask to change it.
		 * ticket_add() rebuilds the ticket from what it is handed, so omitting the block does not
		 * mean "leave capacity alone" — it means "no capacity", and a price-only update silently
		 * zeroed a ticket that had 20 seats. Measured. Carry the current values forward and let the
		 * caller's values overlay them.
		 */
		$capacity = array_merge(
			array(
				'mode'     => $current['unlimited'] ? '' : (string) $current['capacity_mode'],
				'capacity' => (int) $current['capacity'],
			),
			$capacity
		);

		$data['tribe-ticket'] = $capacity;
		$data['ticket_id']    = $ticket_id;

		if ( ! isset( $data['ticket_name'] ) ) {
			// ticket_add() rebuilds the object from what it is given; without the name it blanks it.
			$data['ticket_name'] = $current['name'];
		}

		$saved = Ticket_Repository::save_ticket( $post_id, $data, $current['provider'] );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$fresh = Ticket_Repository::require_ticket( $ticket_id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		return array(
			'ticket'  => Ticket_Repository::describe_ticket( $fresh, $post_id ),
			'message' => sprintf(
				/* translators: %d: ticket ID */
				__( 'Updated ticket %d.', 'acrossai-abilities-manager' ),
				$ticket_id
			),
		);
	}
}
