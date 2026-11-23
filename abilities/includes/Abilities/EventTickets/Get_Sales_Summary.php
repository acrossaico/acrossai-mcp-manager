<?php
/**
 * Feature 110 — Get Sales Summary.
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
 * tickets/get-sales-summary — Get Sales Summary.
 */
final class Get_Sales_Summary extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/get-sales-summary';
	}

	protected function ability_label(): string {
		return __( 'Get Sales Summary', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'What has sold for a post: totals per ticket and an estimated revenue figure from price multiplied by quantity sold. Aggregate only — no purchaser data. The revenue figure is an estimate from current ticket prices, so it will not match the ledger if prices changed after sales were made.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'orders';
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
			'sales' => array( 'type' => 'object' ),
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

		$sales = Ticket_Repository::sales_summary( $post_id );

		return array(
			'sales'   => $sales,
			'message' => sprintf(
				/* translators: 1: tickets sold, 2: estimated revenue */
				__( '%1$d sold, estimated revenue %2$s.', 'acrossai-abilities-manager' ),
				$sales['tickets_sold'],
				(string) $sales['estimated_revenue']
			),
		);
	}
}
