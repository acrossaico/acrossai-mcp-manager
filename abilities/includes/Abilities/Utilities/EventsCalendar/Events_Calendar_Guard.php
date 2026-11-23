<?php
/**
 * Feature 109 — availability, permission and envelope for The Events Calendar suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\EventsCalendar
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventsCalendar;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this suite decides whether it may run, and the one shape it answers in.
 *
 * @since 0.0.34
 */
final class Events_Calendar_Guard {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_events_calendar_permission';

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether The Events Calendar is present and its ORM is loaded.
	 *
	 * The ORM function is checked as well as the class because every write in this suite goes
	 * through `tribe_events()`; a site where the class exists but the template tags have not loaded
	 * would pass a class-only probe and then fatal on the first call.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'Tribe__Events__Main' )
			&& function_exists( 'tribe_events' )
			&& function_exists( 'tribe_get_event' );
	}

	/**
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'events_calendar_missing',
				__( 'The Events Calendar is not active on this site, so there are no events, venues or organizers to work with.', 'acrossai-abilities-manager' )
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
			 * Filters whether the current user may use an Events Calendar ability.
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
