<?php
/**
 * Feature 109 — Get Organizer.
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
 * events/get-organizer — Get Organizer.
 */
final class Get_Organizer extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/get-organizer';
	}

	protected function ability_label(): string {
		return __( 'Get Organizer', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'One organizer with its full contact details.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'organizers';
	}

	protected function input_properties(): array {
		return array(
			'id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The organizer ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'id' );
	}

	protected function output_properties(): array {
		return array(
			'organizer' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$id   = (int) $input['id'];
		$post = Event_Repository::require_post( $id, 'organizer' );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'organizer'   => Event_Repository::describe_linked( $id, 'organizer' ),
			'message' => sprintf(
				/* translators: %s: name */
				__( '%s.', 'acrossai-abilities-manager' ),
				get_the_title( $id )
			),
		);
	}
}
