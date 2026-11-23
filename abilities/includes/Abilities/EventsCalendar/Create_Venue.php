<?php
/**
 * Feature 109 — Create Venue.
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
 * events/create-venue — Create Venue.
 */
final class Create_Venue extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/create-venue';
	}

	protected function ability_label(): string {
		return __( 'Create Venue', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Create a venue through the calendar\'s own data layer so its fields are stored where the calendar expects them. Only a name is required. Defaults to draft.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'venues';
	}

	protected function input_properties(): array {
		return array(
			'title' => array(
				'type'        => 'string',
				'description' => __( 'The venue name.', 'acrossai-abilities-manager' ),
			),
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'Post status.', 'acrossai-abilities-manager' ),
			),
			'address' => array(
				'type'        => 'string',
				'description' => __( 'Street address.', 'acrossai-abilities-manager' ),
			),
			'city' => array(
				'type'        => 'string',
				'description' => __( 'City.', 'acrossai-abilities-manager' ),
			),
			'state' => array(
				'type'        => 'string',
				'description' => __( 'State or province.', 'acrossai-abilities-manager' ),
			),
			'zip' => array(
				'type'        => 'string',
				'description' => __( 'Postal code.', 'acrossai-abilities-manager' ),
			),
			'country' => array(
				'type'        => 'string',
				'description' => __( 'Country.', 'acrossai-abilities-manager' ),
			),
			'phone' => array(
				'type'        => 'string',
				'description' => __( 'Phone number.', 'acrossai-abilities-manager' ),
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
			'venue' => array( 'type' => 'object' ),
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

		foreach ( array( 'title' => 'venue', 'status' => 'status', 'address' => 'address', 'city' => 'city', 'state' => 'state', 'zip' => 'zip', 'country' => 'country', 'phone' => 'phone', 'website' => 'website' ) as $in => $alias ) {
			if ( array_key_exists( $in, $input ) ) {
				$args[ $alias ] = $input[ $in ];
			}
		}

		if ( ! isset( $args['status'] ) ) {
			$args['status'] = 'draft';
		}

		$created = Event_Repository::create( 'venue', $args );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array(
			'venue'   => Event_Repository::describe_linked( (int) $created->ID, 'venue' ),
			'message' => sprintf(
				/* translators: 1: name, 2: ID */
				__( 'Created "%1$s" as venue %2$d.', 'acrossai-abilities-manager' ),
				get_the_title( (int) $created->ID ),
				(int) $created->ID
			),
		);
	}
}
