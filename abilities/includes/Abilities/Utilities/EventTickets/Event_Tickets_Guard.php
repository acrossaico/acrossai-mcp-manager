<?php
/**
 * Feature 110 — availability, permission and envelope for the Event Tickets suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this suite decides whether it may run, and the one shape it answers in.
 *
 * @since 0.0.34
 */
final class Event_Tickets_Guard {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_event_tickets_permission';

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether Event Tickets is present and its provider registry is loaded.
	 *
	 * The abstract provider class is the front door for every read and write in this suite, so it
	 * is checked rather than a version constant alone.
	 *
	 * Note this suite does NOT require The Events Calendar. Event Tickets has no dependency on it —
	 * tickets attach to any post type in `ticket-enabled-post-types`, which defaults to events AND
	 * pages — so gating on the calendar would hide working functionality on a site that only sells
	 * tickets on pages.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'Tribe__Tickets__Main' ) && class_exists( 'Tribe__Tickets__Tickets' );
	}

	/**
	 * Whether Tickets Commerce — the paid provider bundled free — is switched on.
	 *
	 * Reported rather than gated on: RSVP is always available, so most of this suite works with
	 * Tickets Commerce off.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function commerce_enabled(): bool {
		return function_exists( 'tec_tickets_commerce_is_enabled' ) && \tec_tickets_commerce_is_enabled();
	}

	/**
	 * Whether Event Tickets Plus is installed.
	 *
	 * The WooCommerce and EDD providers, attendee custom fields and the check-in app all live
	 * there. An ability that needs one must say so rather than failing obscurely.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function plus_active(): bool {
		return class_exists( 'Tribe__Tickets_Plus__Main' );
	}

	/**
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'event_tickets_missing',
				__( 'Event Tickets is not active on this site, so there are no tickets, attendees or orders to work with.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input   Ability input.
	 * @param  string               $message What the caller is confirming.
	 * @return true|WP_Error
	 */
	public static function assert_confirmed( array $input, string $message = '' ) {
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				'' !== $message ? $message : __( 'Pass confirm: true to proceed.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Permission callback factory.
	 *
	 * The filter is consulted only after the floor passes, so it can tighten and never widen
	 * (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY, and the boolean-filter section added in 106).
	 *
	 * @since  0.0.34
	 * @param  string $floor WordPress capability floor.
	 * @return callable
	 */
	public static function can( string $floor = 'manage_options' ): callable {
		return static function () use ( $floor ): bool {
			if ( ! current_user_can( $floor ) ) {
				return false;
			}

			/**
			 * Filters whether the current user may use an Event Tickets ability.
			 *
			 * @since 0.0.34
			 * @param bool   $allowed Whether access is granted. Always true at this point.
			 * @param string $floor   WordPress capability floor.
			 */
			return (bool) apply_filters( self::PERMISSION_FILTER, true, $floor );
		};
	}

	/**
	 * Success envelope. `success` and `message` are set last so a payload key cannot spoof them.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $payload Ability-specific keys.
	 * @param  string               $message Human-readable summary.
	 * @return array<string, mixed>
	 */
	public static function ok( array $payload, string $message ): array {
		unset( $payload['success'], $payload['error_code'], $payload['message'] );

		return array_merge( array( 'success' => true ), $payload, array( 'message' => $message ) );
	}

	/**
	 * @since  0.0.34
	 * @param  WP_Error $error Error.
	 * @return array<string, mixed>
	 */
	public static function fail( WP_Error $error ): array {
		return self::error( (string) $error->get_error_code(), (string) $error->get_error_message() );
	}

	/**
	 * @since  0.0.34
	 * @param  string $code    Machine-readable code.
	 * @param  string $message Human-readable message.
	 * @return array<string, mixed>
	 */
	public static function error( string $code, string $message ): array {
		return array(
			'success'    => false,
			'error_code' => $code,
			'message'    => $message,
		);
	}
}
