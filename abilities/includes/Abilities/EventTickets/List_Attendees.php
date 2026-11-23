<?php
/**
 * Feature 110 — List Attendees.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventTickets;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets\Ticket_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets\Attendee_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * tickets/list-attendees — List Attendees.
 */
final class List_Attendees extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/list-attendees';
	}

	protected function ability_label(): string {
		return __( 'List Attendees', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The attendees for a post, paginated. Returns ticket, order and check-in status by default and nothing that identifies a person. Names and emails come back only when include_personal_data is true, and the response reports how many records it disclosed. The check-in security code is never returned under any flag — it is the credential printed on the ticket, and disclosing it would let someone check in as another attendee.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'attendees';
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The event, page or other ticketed post.', 'acrossai-abilities-manager' ),
			),
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
		return array( 'post_id' );
	}

	protected function output_properties(): array {
		return array(
			'attendees' => array( 'type' => 'array' ),
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
				'slug'   => 'tickets/get-attendee-summary',
				'reason' => __( 'If you only need counts, use this instead — it discloses nothing.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$post_id = (int) $input['post_id'];
		$post    = Ticket_Repository::require_ticketable( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$with_pii = ! empty( $input['include_personal_data'] );
		$result   = Attendee_Repository::attendees(
			$post_id,
			$with_pii,
			isset( $input['page'] ) ? (int) $input['page'] : 1,
			isset( $input['per_page'] ) ? (int) $input['per_page'] : 20
		);

		$message = sprintf(
			/* translators: 1: returned count, 2: total */
			__( '%1$d of %2$d attendees.', 'acrossai-abilities-manager' ),
			count( $result['rows'] ),
			$result['total']
		);

		if ( $result['disclosed'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of records */
				__( 'Personal data disclosed for %d record(s).', 'acrossai-abilities-manager' ),
				$result['disclosed']
			);
		}

		return array(
			'attendees' => $result['rows'],
			'total'     => $result['total'],
			'disclosed' => $result['disclosed'],
			'message'   => $message,
		);
	}
}
