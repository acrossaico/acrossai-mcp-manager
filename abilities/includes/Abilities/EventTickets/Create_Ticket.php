<?php
/**
 * Feature 110 — Create Ticket.
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
 * tickets/create-ticket — Create Ticket.
 */
final class Create_Ticket extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/create-ticket';
	}

	protected function ability_label(): string {
		return __( 'Create Ticket', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Add a ticket to a post through Event Tickets\' own save path, so capacity and stock are set the way the plugin expects. Refuses a post type that is not enabled for tickets, naming the ones that are.',
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
		return array( 'post_id', 'name' );
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
			'idempotent'  => false,
		);
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'tickets/list-ticket-providers',
				'reason' => __( 'Check which providers are active before choosing one.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$post_id = (int) $input['post_id'];
		$post    = Ticket_Repository::require_ticketable( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
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

		if ( array() !== $capacity ) {
			$data['tribe-ticket'] = $capacity;
		}

		$ticket_id = Ticket_Repository::save_ticket( $post_id, $data, isset( $input['provider'] ) ? (string) $input['provider'] : '' );

		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}

		$ticket = Ticket_Repository::require_ticket( (int) $ticket_id );

		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		return array(
			'ticket'  => Ticket_Repository::describe_ticket( $ticket, $post_id ),
			'message' => sprintf(
				/* translators: 1: ticket name, 2: ticket ID */
				__( 'Created "%1$s" as ticket %2$d.', 'acrossai-abilities-manager' ),
				(string) ( $input['name'] ?? '' ),
				(int) $ticket_id
			),
		);
	}
}
