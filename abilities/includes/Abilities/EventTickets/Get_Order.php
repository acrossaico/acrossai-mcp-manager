<?php
/**
 * Feature 110 — Get Order.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventTickets;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets\Attendee_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * tickets/get-order — Get Order.
 */
final class Get_Order extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/get-order';
	}

	protected function ability_label(): string {
		return __( 'Get Order', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'One Tickets Commerce order: status, subtotal, total, currency, gateway name and date. Purchaser name and email only when include_personal_data is true. Gateway payloads, processor order identifiers and customer references are never returned.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'orders';
	}

	protected function input_properties(): array {
		return array(
			'order_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The order ID.', 'acrossai-abilities-manager' ),
			),
			'include_personal_data' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Include names and email addresses. Off by default: this data leaves the site when an assistant reads it. The response reports how many records were disclosed. The check-in security code is never returned, with or without this flag.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'order_id' );
	}

	protected function output_properties(): array {
		return array(
			'order' => array( 'type' => 'object' ),
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
		$order_id = (int) $input['order_id'];
		$row      = Attendee_Repository::order( $order_id, ! empty( $input['include_personal_data'] ) );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return array(
			'order'   => $row,
			'message' => sprintf(
				/* translators: 1: order ID, 2: status, 3: total */
				__( 'Order %1$d is %2$s, total %3$s.', 'acrossai-abilities-manager' ),
				$order_id,
				$row['status'],
				$row['total']
			),
		);
	}
}
