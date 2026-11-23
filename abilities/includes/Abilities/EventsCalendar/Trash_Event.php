<?php
/**
 * Feature 109 — Trash Event.
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
 * events/trash-event — Trash Event.
 */
final class Trash_Event extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/trash-event';
	}

	protected function ability_label(): string {
		return __( 'Trash Event', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Move an event to the trash. Trash, never permanent deletion — WordPress only auto-trashes ordinary posts and pages, so a calendar post handed to the delete function would be removed outright with no way back. Refuses if trash is disabled on the site.',
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
			'id' => array( 'type' => 'integer' ),
			'trashed' => array( 'type' => 'boolean' ),
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
		return __( 'Trashing an event removes it from the calendar and from any listing that includes it. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	protected function run( array $input ) {
		$id    = (int) $input['id'];
		$event = Event_Repository::require_post( $id, 'event' );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$title   = (string) get_the_title( $id );
		$trashed = Event_Repository::trash( $id );

		if ( is_wp_error( $trashed ) ) {
			return $trashed;
		}

		return array(
			'id'      => $id,
			'trashed' => true,
			'message' => sprintf(
				/* translators: %s: event title */
				__( 'Moved "%s" to the trash. It can be restored from the trash until it is emptied.', 'acrossai-abilities-manager' ),
				$title
			),
		);
	}
}
