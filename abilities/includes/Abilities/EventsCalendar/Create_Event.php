<?php
/**
 * Feature 109 — Create Event.
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
 * events/create-event — Create Event.
 */
final class Create_Event extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/create-event';
	}

	protected function ability_label(): string {
		return __( 'Create Event', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Create an event through the calendar\'s own data layer, so its dates land consistently in post meta and in the calendar\'s tables. Needs at least a title and an end date. Defaults to draft. Venue and organizer IDs are checked before the write, because the calendar discards a link it cannot resolve without saying so.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'events';
	}

	protected function input_properties(): array {
		return array(
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
		return array( 'title' );
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
			'idempotent'  => false,
		);
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'events/list-venues',
				'reason' => __( 'Find or confirm the venue ID before creating.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'events/list-organizers',
				'reason' => __( 'Find or confirm the organizer IDs before creating.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'events/set-event-categories',
				'reason' => __( 'Assign categories once the event exists.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
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

		if ( ! isset( $args['status'] ) ) {
			$args['status'] = 'draft';
		}

		$created = Event_Repository::create( 'event', $args );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$dates = Event_Repository::assert_dates_applied( (int) $created->ID, $args );

		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		return array(
			'event'   => Event_Repository::describe_event( (int) $created->ID ),
			'message' => sprintf(
				/* translators: 1: event title, 2: event ID */
				__( 'Created "%1$s" as event %2$d.', 'acrossai-abilities-manager' ),
				get_the_title( (int) $created->ID ),
				(int) $created->ID
			),
		);
	}
}
