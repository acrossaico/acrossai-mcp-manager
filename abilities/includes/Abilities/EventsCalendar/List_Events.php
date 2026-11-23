<?php
/**
 * Feature 109 — List Events.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventsCalendar
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventsCalendar;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventsCalendar\Event_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * events/list-events — List Events.
 */
final class List_Events extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/list-events';
	}

	protected function ability_label(): string {
		return __( 'List Events', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Find events by date range, venue, organizer, category, cost or featured flag. This is the ability that answers calendar questions — starts_after and starts_before take any date string WordPress understands, so "what is on next week" is one call. Returns each event with its dates, timezone, venue and organizers already resolved.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'events';
	}

	protected function input_properties(): array {
		return array(
			'starts_after' => array(
				'type'        => 'string',
				'description' => __( 'Only events starting on or after this date. Accepts anything strtotime understands, e.g. "2026-11-01" or "next monday".', 'acrossai-abilities-manager' ),
			),
			'starts_before' => array(
				'type'        => 'string',
				'description' => __( 'Only events starting before this date.', 'acrossai-abilities-manager' ),
			),
			'ends_after' => array(
				'type'        => 'string',
				'description' => __( 'Only events ending after this date.', 'acrossai-abilities-manager' ),
			),
			'venue' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Only events at this venue.', 'acrossai-abilities-manager' ),
			),
			'organizer' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Only events with this organizer.', 'acrossai-abilities-manager' ),
			),
			'category' => array(
				'type'        => 'string',
				'description' => __( 'Event category slug or ID.', 'acrossai-abilities-manager' ),
			),
			'featured' => array(
				'type'        => 'boolean',
				'description' => __( 'Only featured events.', 'acrossai-abilities-manager' ),
			),
			'search' => array(
				'type'        => 'string',
				'description' => __( 'Free-text search over title and content.', 'acrossai-abilities-manager' ),
			),
			'status' => array(
				'type'        => 'string',
				'default'     => 'publish',
				'description' => __( 'Post status, or "any".', 'acrossai-abilities-manager' ),
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
				'description' => __( 'Results per page, maximum 100.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(  );
	}

	protected function output_properties(): array {
		return array(
			'events' => array( 'type' => 'array' ),
			'total' => array( 'type' => 'integer' ),
			'page' => array( 'type' => 'integer' ),
			'per_page' => array( 'type' => 'integer' ),
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
				'slug'   => 'events/get-event',
				'reason' => __( 'Read one event in full, including its categories.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'taxonomies/list-terms',
				'reason' => __( 'To browse the event categories themselves, list terms in the tribe_events_cat taxonomy.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$filters = array();
		$map     = array(
			'starts_after'  => 'starts_after',
			'starts_before' => 'starts_before',
			'ends_after'    => 'ends_after',
			'venue'         => 'venue',
			'organizer'     => 'organizer',
			'featured'      => 'featured',
			'search'        => 'search',
			'status'        => 'post_status',
		);

		foreach ( $map as $in => $filter ) {
			if ( isset( $input[ $in ] ) && '' !== $input[ $in ] ) {
				$filters[ $filter ] = $input[ $in ];
			}
		}

		if ( ! empty( $input['category'] ) ) {
			$filters['event_category'] = $input['category'];
		}

		$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$result   = Event_Repository::query_events( $filters, $page, $per_page );

		return array(
			'events'   => $result['rows'],
			'total'    => $result['total'],
			'page'     => $page,
			'per_page' => $per_page,
			'message'  => sprintf(
				/* translators: 1: returned count, 2: total */
				__( '%1$d of %2$d matching events.', 'acrossai-abilities-manager' ),
				count( $result['rows'] ),
				$result['total']
			),
		);
	}
}
