<?php
/**
 * Feature 109 — Create Organizer.
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
 * events/create-organizer — Create Organizer.
 */
final class Create_Organizer extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/create-organizer';
	}

	protected function ability_label(): string {
		return __( 'Create Organizer', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Create a organizer through the calendar\'s own data layer so its fields are stored where the calendar expects them. Only a name is required. Defaults to draft.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'organizers';
	}

	protected function input_properties(): array {
		return array(
			'title' => array(
				'type'        => 'string',
				'description' => __( 'The organizer name.', 'acrossai-abilities-manager' ),
			),
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'Post status.', 'acrossai-abilities-manager' ),
			),
			'phone' => array(
				'type'        => 'string',
				'description' => __( 'Phone number.', 'acrossai-abilities-manager' ),
			),
			'email' => array(
				'type'        => 'string',
				'description' => __( 'Email address.', 'acrossai-abilities-manager' ),
			),
			'website' => array(
				'type'        => 'string',
				'description' => __( 'Website URL.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'title' );
	}

	protected function output_properties(): array {
		return array(
			'organizer' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	protected function run( array $input ) {
		$args = array();

		foreach ( array( 'title' => 'organizer', 'status' => 'status', 'phone' => 'phone', 'email' => 'email', 'website' => 'website' ) as $in => $alias ) {
			if ( array_key_exists( $in, $input ) ) {
				$args[ $alias ] = $input[ $in ];
			}
		}

		if ( ! isset( $args['status'] ) ) {
			$args['status'] = 'draft';
		}

		$created = Event_Repository::create( 'organizer', $args );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array(
			'organizer'   => Event_Repository::describe_linked( (int) $created->ID, 'organizer' ),
			'message' => sprintf(
				/* translators: 1: name, 2: ID */
				__( 'Created "%1$s" as organizer %2$d.', 'acrossai-abilities-manager' ),
				get_the_title( (int) $created->ID ),
				(int) $created->ID
			),
		);
	}
}
