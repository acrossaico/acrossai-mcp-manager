<?php
/**
 * Feature 110 — Set Ticket Capacity.
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
 * tickets/set-ticket-capacity — Set Ticket Capacity.
 */
final class Set_Ticket_Capacity extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/set-ticket-capacity';
	}

	protected function ability_label(): string {
		return __( 'Set Ticket Capacity', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change a ticket\'s capacity and mode on their own, without touching its name or price. Goes through the plugin\'s capacity handling, which subtracts pending and sold from the new stock — that reconciliation is the reason to use this rather than writing the capacity meta, which would double-count every sale already made and oversell the event. The result is read back and refused if it did not take.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'capacity';
	}

	protected function input_properties(): array {
		return array(
			'ticket_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The ticket ID.', 'acrossai-abilities-manager' ),
			),
			'capacity' => array(
				'type'        => 'integer',
				'description' => __( 'New capacity. -1 for unlimited.', 'acrossai-abilities-manager' ),
			),
			'capacity_mode' => array(
				'type'        => 'string',
				'enum'        => array( 'own', 'global', 'capped', 'unlimited' ),
				'description' => __( 'Which stock model the ticket uses.', 'acrossai-abilities-manager' ),
			),
			'shared_cap' => array(
				'type'        => 'integer',
				'description' => __( 'The limit when the mode is capped.', 'acrossai-abilities-manager' ),
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

		$current = Ticket_Repository::describe_ticket( $existing );
		$post_id = (int) $current['event_id'];

		if ( ! $post_id ) {
			return new WP_Error( 'ticket_unattached', __( 'That ticket is not attached to a post.', 'acrossai-abilities-manager' ) );
		}

		$capacity = array();

		if ( isset( $input['capacity_mode'] ) ) {
			$capacity['mode'] = 'unlimited' === $input['capacity_mode'] ? '' : (string) $input['capacity_mode'];
		}

		if ( isset( $input['capacity'] ) ) {
			$capacity['capacity'] = (int) $input['capacity'];
		}

		if ( isset( $input['shared_cap'] ) ) {
			$capacity['event_capacity'] = (int) $input['shared_cap'];
		}

		if ( array() === $capacity ) {
			return new WP_Error( 'invalid_input', __( 'Supply capacity, capacity_mode, or both.', 'acrossai-abilities-manager' ) );
		}

		// Fill in whichever half the caller left out, for the same reason Update_Ticket does:
		// ticket_add() treats an absent value as zero rather than as "unchanged".
		$capacity = array_merge(
			array(
				'mode'     => $current['unlimited'] ? '' : (string) $current['capacity_mode'],
				'capacity' => (int) $current['capacity'],
			),
			$capacity
		);

		$saved = Ticket_Repository::save_ticket(
			$post_id,
			array(
				'ticket_id'    => $ticket_id,
				'ticket_name'  => $current['name'],
				'ticket_price' => $current['price'],
				'tribe-ticket' => $capacity,
			),
			$current['provider']
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$fresh = Ticket_Repository::require_ticket( $ticket_id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$row = Ticket_Repository::describe_ticket( $fresh, $post_id );

		if ( isset( $input['capacity'] ) && (int) $input['capacity'] !== (int) $row['capacity'] ) {
			return new WP_Error(
				'capacity_rejected',
				sprintf(
					/* translators: 1: requested capacity, 2: stored capacity */
					__( 'Event Tickets did not apply the capacity: asked for %1$d, the ticket holds %2$d. It refuses a capacity below what is already sold.', 'acrossai-abilities-manager' ),
					(int) $input['capacity'],
					(int) $row['capacity']
				)
			);
		}

		return array(
			'ticket'  => $row,
			'message' => sprintf(
				/* translators: 1: capacity, 2: mode */
				__( 'Capacity is now %1$s in %2$s mode.', 'acrossai-abilities-manager' ),
				$row['unlimited'] ? __( 'unlimited', 'acrossai-abilities-manager' ) : (string) $row['capacity'],
				$row['capacity_mode']
			),
		);
	}
}
