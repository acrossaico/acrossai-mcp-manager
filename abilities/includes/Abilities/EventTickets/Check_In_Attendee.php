<?php
/**
 * Feature 110 — Check In Attendee.
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
 * tickets/check-in-attendee — Check In Attendee.
 */
final class Check_In_Attendee extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/check-in-attendee';
	}

	protected function ability_label(): string {
		return __( 'Check In Attendee', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Mark an attendee as checked in. The state is read back afterwards, because Event Tickets exposes a filter over check-in and a site can veto it — without the read-back a vetoed check-in would report success.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'attendees';
	}

	protected function input_properties(): array {
		return array(
			'attendee_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The attendee ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'attendee_id' );
	}

	protected function output_properties(): array {
		return array(
			'attendee_id' => array( 'type' => 'integer' ),
			'checked_in' => array( 'type' => 'boolean' ),
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
		$attendee_id = (int) $input['attendee_id'];
		$result      = Attendee_Repository::set_check_in( $attendee_id, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'attendee_id' => $result['attendee_id'],
			'checked_in'  => $result['checked_in'],
			'message'     => sprintf(
				/* translators: %d: attendee ID */
				__( 'Attendee %d is now checked in.', 'acrossai-abilities-manager' ),
				$attendee_id
			),
		);
	}
}
