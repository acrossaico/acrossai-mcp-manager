<?php
/**
 * Feature 109 — List Venues.
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
 * events/list-venues — List Venues.
 */
final class List_Venues extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/list-venues';
	}

	protected function ability_label(): string {
		return __( 'List Venues', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every venue on the calendar, with its contact details, newest first. Use this to find the ID an event needs before creating or rescheduling one.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'venues';
	}

	protected function input_properties(): array {
		return array(
			'search' => array(
				'type'        => 'string',
				'description' => __( 'Free-text search over the name.', 'acrossai-abilities-manager' ),
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
			'venues' => array( 'type' => 'array' ),
			'total' => array( 'type' => 'integer' ),
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
				'slug'   => 'events/list-events',
				'reason' => __( 'Filter events by this venue once you have its ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$result   = Event_Repository::query_linked( 'venue', $search, $page, $per_page );

		return array(
			'venues'  => $result['rows'],
			'total' => $result['total'],
			'message' => sprintf(
				/* translators: 1: returned count, 2: total */
				__( '%1$d of %2$d venues.', 'acrossai-abilities-manager' ),
				count( $result['rows'] ),
				$result['total']
			),
		);
	}
}
