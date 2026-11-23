<?php
/**
 * Feature 109 — Get Venue.
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
 * events/get-venue — Get Venue.
 */
final class Get_Venue extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/get-venue';
	}

	protected function ability_label(): string {
		return __( 'Get Venue', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'One venue with its full contact details.',
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
			'venue' => array( 'type' => 'object' ),
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
		$post = Event_Repository::require_post( $id, 'venue' );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'venue'   => Event_Repository::describe_linked( $id, 'venue' ),
			'message' => sprintf(
				/* translators: %s: name */
				__( '%s.', 'acrossai-abilities-manager' ),
				get_the_title( $id )
			),
		);
	}
}
