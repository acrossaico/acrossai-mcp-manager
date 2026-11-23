<?php
/**
 * Feature 109 — Trash Venue.
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
 * events/trash-venue — Trash Venue.
 */
final class Trash_Venue extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/trash-venue';
	}

	protected function ability_label(): string {
		return __( 'Trash Venue', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Move a venue to the trash. Reports how many events still point at it first — the calendar does not clear those links, so they would be left referencing a trashed post. Trash only, never permanent deletion.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'venues';
	}

	protected function input_properties(): array {
		return array(
			'id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The venue ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'id' );
	}

	protected function output_properties(): array {
		return array(
			'id' => array( 'type' => 'integer' ),
			'trashed' => array( 'type' => 'boolean' ),
			'linked_events' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __( 'Events already linked to this are not updated — they keep pointing at it. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	protected function run( array $input ) {
		$id   = (int) $input['id'];
		$post = Event_Repository::require_post( $id, 'venue' );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$linked  = Event_Repository::linked_event_count( $id, 'venue' );
		$title   = (string) get_the_title( $id );
		$trashed = Event_Repository::trash( $id );

		if ( is_wp_error( $trashed ) ) {
			return $trashed;
		}

		return array(
			'id'            => $id,
			'trashed'       => true,
			'linked_events' => $linked,
			'message'       => sprintf(
				/* translators: 1: name, 2: number of events */
				__( 'Trashed "%1$s". %2$d event(s) still reference it and were not changed.', 'acrossai-abilities-manager' ),
				$title,
				$linked
			),
		);
	}
}
