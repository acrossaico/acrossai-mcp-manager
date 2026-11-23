<?php
/**
 * Feature 109 — Get Event.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventsCalendar
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventsCalendar;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventsCalendar\Event_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * events/get-event — Get Event.
 */
final class Get_Event extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/get-event';
	}

	protected function ability_label(): string {
		return __( 'Get Event', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'One event with everything resolved: local and UTC start and end, timezone, all-day and multiday flags, cost, venue and organizer details, and its categories. Also reports whether the event is recurring, which this suite will not edit.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'events';
	}

	protected function input_properties(): array {
		return array(
			'id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The event ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'id' );
	}

	protected function output_properties(): array {
		return array(
			'event' => array( 'type' => 'object' ),
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
				'slug'   => 'events/update-event',
				'reason' => __( 'Change its dates, venue, organizers or cost.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'events/list-events',
				'reason' => __( 'Find related events at the same venue or in the same category.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$id    = (int) $input['id'];
		$event = Event_Repository::require_post( $id, 'event' );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$row = Event_Repository::describe_event( $id );

		if ( null === $row ) {
			return new WP_Error( 'event_unreadable', __( 'The Events Calendar could not decorate that event.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'event'   => $row,
			'message' => sprintf(
				/* translators: 1: event title, 2: start date */
				__( '"%1$s" starts %2$s.', 'acrossai-abilities-manager' ),
				$row['title'],
				$row['start_date']
			),
		);
	}
}
