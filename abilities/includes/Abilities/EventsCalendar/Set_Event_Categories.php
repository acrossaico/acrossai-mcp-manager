<?php
/**
 * Feature 109 — Set Event Categories.
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
 * events/set-event-categories — Set Event Categories.
 */
final class Set_Event_Categories extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/set-event-categories';
	}

	protected function ability_label(): string {
		return __( 'Set Event Categories', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Assign event categories to an event: replace the current set, add to it, or remove from it. Unknown term IDs are refused by name rather than silently ignored.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'categories';
	}

	protected function input_properties(): array {
		return array(
			'id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The event ID.', 'acrossai-abilities-manager' ),
			),
			'term_ids' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Event category term IDs.', 'acrossai-abilities-manager' ),
			),
			'mode' => array(
				'type'        => 'string',
				'enum'        => array( 'replace', 'add', 'remove' ),
				'default'     => 'replace',
				'description' => __( 'replace sets exactly these, add appends, remove detaches.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'id', 'term_ids' );
	}

	protected function output_properties(): array {
		return array(
			'categories' => array( 'type' => 'array' ),
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
		$id    = (int) $input['id'];
		$event = Event_Repository::require_post( $id, 'event' );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$mode  = isset( $input['mode'] ) ? (string) $input['mode'] : 'replace';
		$terms = array_map( 'absint', (array) $input['term_ids'] );
		$rows  = Event_Repository::set_categories( $id, $terms, $mode );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return array(
			'categories' => $rows,
			'message'    => sprintf(
				/* translators: 1: event ID, 2: resulting category count */
				__( 'Event %1$d now has %2$d categor(ies).', 'acrossai-abilities-manager' ),
				$id,
				count( $rows )
			),
		);
	}
}
