<?php
/**
 * Feature 104 — shared guards, permission factory and response envelope for the LiteSpeed Cache
 * ability suite.
 *
 * Every `LiteSpeed\*` symbol used by the suite is reached through this directory. No ability class
 * may name one directly — Test_LiteSpeed_Architecture asserts that.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only guard + envelope helpers.
 */
final class LiteSpeed_Guard {

	/**
	 * Filter name allowing site owners to relax the capability policy.
	 *
	 * Evaluated inside can() so a single filter governs every ability in the suite.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_litespeed_permission';

	/**
	 * The class every ability ultimately reaches through.
	 *
	 * `LiteSpeed\Core` rather than the `LSCWP_V` version constant: its presence also proves the
	 * plugin's autoloader is live, which the constant does not.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	private const PROBE_CLASS = '\\LiteSpeed\\Core';

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether LiteSpeed Cache is loaded.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( self::PROBE_CLASS );
	}

	/**
	 * Assert LiteSpeed Cache is loaded.
	 *
	 * Called first by every execute() as defence in depth: the bootstrap already gates instantiation,
	 * but the plugin can be deactivated after the abilities were registered in the same request.
	 *
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'litespeed_missing',
				__( 'LiteSpeed Cache is not active.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Assert the caller confirmed an irreversible or site-wide operation.
	 *
	 * @since  0.0.34
	 * @param  array<string,mixed> $input   Ability input.
	 * @param  string              $message Optional operation-specific message.
	 * @return true|WP_Error
	 */
	public static function assert_confirmed( array $input, string $message = '' ) {
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				'' !== $message
					? $message
					: __( 'This operation cannot be undone. Pass confirm: true to proceed.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Build the permission_callback for one ability.
	 *
	 * A SINGLE `current_user_can( $floor )`, never OR-ed with a default, so the filter can only ever
	 * raise the requirement (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY).
	 *
	 * Unlike Contact Form 7, LiteSpeed defines no granular capabilities of its own — every one of its
	 * admin screens is `manage_options` — so there is nothing to compose with and the floor stands
	 * alone. It is still declared `final` on the base, for the same reason: to stop a future subclass
	 * quietly lowering it.
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
			 * Filters whether the current user may use a LiteSpeed Cache ability.
			 *
			 * Consulted only for a user who already clears the floor, so it can deny but never
			 * grant (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY). Returning the filter's value
			 * directly would let any plugin on the site hand an ability to a subscriber.
			 *
			 * @since 0.0.34
			 * @param bool   $allowed Whether access is granted. Always true at this point.
			 * @param string $floor   WordPress capability floor.
			 */
			return (bool) apply_filters( self::PERMISSION_FILTER, true, $floor );
		};
	}

	/**
	 * Build a success envelope.
	 *
	 * @since  0.0.34
	 * @param  array<string,mixed> $payload Ability-specific keys.
	 * @param  string              $message Human-readable summary.
	 * @return array<string,mixed>
	 */
	public static function ok( array $payload, string $message ): array {
		unset( $payload['success'], $payload['message'], $payload['error_code'] );

		return array_merge( array( 'success' => true ), $payload, array( 'message' => $message ) );
	}

	/**
	 * Build a failure envelope from a WP_Error.
	 *
	 * @since  0.0.34
	 * @param  WP_Error $error Error to unwrap.
	 * @return array<string,mixed>
	 */
	public static function fail( WP_Error $error ): array {
		return self::error( (string) $error->get_error_code(), (string) $error->get_error_message() );
	}

	/**
	 * Build a failure envelope from an explicit code and message.
	 *
	 * @since  0.0.34
	 * @param  string $code    Machine-readable error code.
	 * @param  string $message Human-readable message.
	 * @return array<string,mixed>
	 */
	public static function error( string $code, string $message ): array {
		return array(
			'success'    => false,
			'error_code' => $code,
			'message'    => $message,
		);
	}
}
