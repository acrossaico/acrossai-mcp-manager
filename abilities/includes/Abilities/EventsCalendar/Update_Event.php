<?php
/**
 * Feature 109 — Update Event.
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
 * events/update-event — Update Event.
 */
final class Update_Event extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/update-event';
	}

	protected function ability_label(): string {
		return __( 'Update Event', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change an existing event through the calendar\'s own data layer. Only the fields supplied are touched. Dates are read back after the write and reported as rejected if the calendar discarded them, which it does silently when the end precedes the start. Refuses recurring events — a series cannot be edited safely from here.',
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
			'title' => array(
				'type'        => 'string',
				'description' => __( 'The event title.', 'acrossai-abilities-manager' ),
			),
			'description' => array(
				'type'        => 'string',
				'description' => __( 'The event body content.', 'acrossai-abilities-manager' ),
			),
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'Post status.', 'acrossai-abilities-manager' ),
			),
			'start_date' => array(
				'type'        => 'string',
				'description' => __( 'Local start, e.g. "2026-11-02 09:00:00". Interpreted in the event timezone.', 'acrossai-abilities-manager' ),
			),
			'end_date' => array(
				'type'        => 'string',
				'description' => __( 'Local end. Must not precede the start — the calendar discards the whole date block if it does, and this ability reports that rather than claiming success.', 'acrossai-abilities-manager' ),
			),
			'all_day' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the event runs all day.', 'acrossai-abilities-manager' ),
			),
			'timezone' => array(
				'type'        => 'string',
				'description' => __( 'Olson timezone, e.g. "America/New_York". The UTC times and the timezone abbreviation are derived from this.', 'acrossai-abilities-manager' ),
			),
			'venue' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Venue ID. Rejected if it is not a venue, because the calendar would silently drop the link.', 'acrossai-abilities-manager' ),
			),
			'organizers' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Organizer IDs. An empty array clears them.', 'acrossai-abilities-manager' ),
			),
			'cost' => array(
				'type'        => 'string',
				'description' => __( 'Cost as displayed, e.g. "25" or "Free".', 'acrossai-abilities-manager' ),
			),
			'url' => array(
				'type'        => 'string',
				'description' => __( 'External event website.', 'acrossai-abilities-manager' ),
			),
			'featured' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the event is featured.', 'acrossai-abilities-manager' ),
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
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'events/get-event',
				'reason' => __( 'Read the current values first — this replaces the fields you supply.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$id    = (int) $input['id'];
		$event = Event_Repository::require_post( $id, 'event' );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$recurring = Event_Repository::assert_not_recurring( $id );

		if ( is_wp_error( $recurring ) ) {
			return $recurring;
		}

		$args = array();
		$map  = array(
			'title'       => 'title',
			'description' => 'content',
			'status'      => 'status',
			'start_date'  => 'start_date',
			'end_date'    => 'end_date',
			'all_day'     => 'all_day',
			'timezone'    => 'timezone',
			'venue'       => 'venue',
			'organizers'  => 'organizers',
			'cost'        => 'cost',
			'url'         => 'url',
			'featured'    => 'featured',
		);

		foreach ( $map as $in => $alias ) {
			if ( array_key_exists( $in, $input ) ) {
				$args[ $alias ] = $input[ $in ];
			}
		}

		$links = Event_Repository::validate_links( $args );

		if ( is_wp_error( $links ) ) {
			return $links;
		}

		if ( array() === $args ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one field to change.', 'acrossai-abilities-manager' ) );
		}

		$saved = Event_Repository::update( 'event', $id, $args );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$dates = Event_Repository::assert_dates_applied( $id, $args );

		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		return array(
			'event'   => Event_Repository::describe_event( $id ),
			'message' => sprintf(
				/* translators: 1: comma-separated field names, 2: event ID */
				__( 'Updated %1$s on event %2$d.', 'acrossai-abilities-manager' ),
				implode( ', ', array_keys( $args ) ),
				$id
			),
		);
	}
}
