<?php
/**
 * Feature 109 — List Event Categories.
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
 * events/list-event-categories — List Event Categories.
 */
final class List_Event_Categories extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/list-event-categories';
	}

	protected function ability_label(): string {
		return __( 'List Event Categories', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every event category with the number of events in each, including empty ones. Event categories are ordinary WordPress terms, so creating, renaming and deleting them is done with the taxonomy abilities — this exists because those cannot tell you how many events sit in each.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'categories';
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array(  );
	}

	protected function output_properties(): array {
		return array(
			'categories' => array( 'type' => 'array' ),
			'count' => array( 'type' => 'integer' ),
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
				'slug'   => 'taxonomies/create-term',
				'reason' => __( 'Create a new event category — pass taxonomy tribe_events_cat.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'events/set-event-categories',
				'reason' => __( 'Assign categories to an event.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$rows = Event_Repository::categories();

		return array(
			'categories' => $rows,
			'count'      => count( $rows ),
			'message'    => sprintf(
				/* translators: %d: number of categories */
				_n( '%d event category.', '%d event categories.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
