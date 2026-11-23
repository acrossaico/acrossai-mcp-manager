<?php
/**
 * Feature 110 — Get Attendee Summary.
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
 * tickets/get-attendee-summary — Get Attendee Summary.
 */
final class Get_Attendee_Summary extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/get-attendee-summary';
	}

	protected function ability_label(): string {
		return __( 'Get Attendee Summary', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'How many people are coming, how many have checked in, and the breakdown per ticket. No names, no emails, no identifying data of any kind — this is the ability to reach for when the question is about numbers, which it usually is.',
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
		);
	}

	protected function required_input(): array {
		return array( 'post_id' );
	}

	protected function output_properties(): array {
		return array(
			'summary' => array( 'type' => 'object' ),
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
				'slug'   => 'tickets/list-attendees',
				'reason' => __( 'When you genuinely need who rather than how many. That one discloses personal data and says so.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$post_id = (int) $input['post_id'];
		$post    = Ticket_Repository::require_ticketable( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$summary = Attendee_Repository::summary( $post_id );

		return array(
			'summary' => $summary,
			'message' => sprintf(
				/* translators: 1: attendee count, 2: checked-in count */
				__( '%1$d attendee(s), %2$d checked in.', 'acrossai-abilities-manager' ),
				$summary['attendees'],
				$summary['checked_in']
			),
		);
	}
}
