<?php
/**
 * Feature 110 — List Orders.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventTickets;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets\Attendee_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * tickets/list-orders — List Orders.
 */
final class List_Orders extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/list-orders';
	}

	protected function ability_label(): string {
		return __( 'List Orders', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Tickets Commerce orders, paginated, newest first. Status, total, currency, gateway name and date by default. Purchaser names and emails only when include_personal_data is true, with the disclosure counted in the response. Raw gateway payloads and processor customer identifiers are never returned.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'orders';
	}

	protected function input_properties(): array {
		return array(
			'include_personal_data' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Include names and email addresses. Off by default: this data leaves the site when an assistant reads it. The response reports how many records were disclosed. The check-in security code is never returned, with or without this flag.', 'acrossai-abilities-manager' ),
			),
			'page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( '1-based page number.', 'acrossai-abilities-manager' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => 20,
				'description' => __( 'Results per page, capped at 100.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(  );
	}

	protected function output_properties(): array {
		return array(
			'orders' => array( 'type' => 'array' ),
			'total' => array( 'type' => 'integer' ),
			'disclosed' => array( 'type' => 'integer' ),
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
				'slug'   => 'tickets/get-sales-summary',
				'reason' => __( 'For totals rather than individual orders — it discloses nothing.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$with_pii = ! empty( $input['include_personal_data'] );
		$result   = Attendee_Repository::orders(
			$with_pii,
			isset( $input['page'] ) ? (int) $input['page'] : 1,
			isset( $input['per_page'] ) ? (int) $input['per_page'] : 20
		);

		$message = sprintf(
			/* translators: 1: returned count, 2: total */
			__( '%1$d of %2$d orders.', 'acrossai-abilities-manager' ),
			count( $result['rows'] ),
			$result['total']
		);

		if ( $result['disclosed'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of records */
				__( 'Personal data disclosed for %d order(s).', 'acrossai-abilities-manager' ),
				$result['disclosed']
			);
		}

		return array(
			'orders'    => $result['rows'],
			'total'     => $result['total'],
			'disclosed' => $result['disclosed'],
			'message'   => $message,
		);
	}
}
