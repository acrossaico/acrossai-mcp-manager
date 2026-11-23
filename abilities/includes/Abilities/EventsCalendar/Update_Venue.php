<?php
/**
 * Feature 109 — Update Venue.
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
 * events/update-venue — Update Venue.
 */
final class Update_Venue extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/update-venue';
	}

	protected function ability_label(): string {
		return __( 'Update Venue', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change an existing venue. Only the fields supplied are touched. Events already linked to it pick the change up automatically, since they store only its ID.',
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
		return array( 'id' );
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
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$id   = (int) $input['id'];
		$post = Event_Repository::require_post( $id, 'venue' );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$args = array();

		foreach ( array( 'title' => 'venue', 'status' => 'status', 'address' => 'address', 'city' => 'city', 'state' => 'state', 'zip' => 'zip', 'country' => 'country', 'phone' => 'phone', 'website' => 'website' ) as $in => $alias ) {
			if ( array_key_exists( $in, $input ) ) {
				$args[ $alias ] = $input[ $in ];
			}
		}

		if ( array() === $args ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one field to change.', 'acrossai-abilities-manager' ) );
		}

		$saved = Event_Repository::update( 'venue', $id, $args );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'venue'   => Event_Repository::describe_linked( $id, 'venue' ),
			'message' => sprintf(
				/* translators: 1: comma-separated field names, 2: ID */
				__( 'Updated %1$s on venue %2$d.', 'acrossai-abilities-manager' ),
				implode( ', ', array_keys( $args ) ),
				$id
			),
		);
	}
}
