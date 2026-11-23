<?php
/**
 * Feature 110 — Get Capacity Report.
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
 * tickets/get-capacity-report — Get Capacity Report.
 */
final class Get_Capacity_Report extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/get-capacity-report';
	}

	protected function ability_label(): string {
		return __( 'Get Capacity Report', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The whole capacity picture for a post: whether a shared pool is in use and how much of it is left, plus every ticket with its own capacity, mode, stock, sold, pending and available. A ticket in global or capped mode has no meaningful capacity of its own — it draws from the pool — so reading one ticket in isolation tells you nothing about how many seats remain.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'capacity';
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
			'capacity' => array( 'type' => 'object' ),
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
		$post_id = (int) $input['post_id'];
		$post    = Ticket_Repository::require_ticketable( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$report = Ticket_Repository::capacity_report( $post_id );

		return array(
			'capacity' => $report,
			'message'  => sprintf(
				/* translators: 1: number of tickets, 2: sold count */
				__( '%1$d ticket type(s), %2$d sold.', 'acrossai-abilities-manager' ),
				count( $report['tickets'] ),
				$report['total_sold']
			),
		);
	}
}
