<?php
/**
 * Feature 121 — availability, permission and envelope for the store suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this suite decides whether it may run, and the one shape it answers in.
 *
 * @since 0.0.34
 */
final class Store_Guard {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_store_permission';

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether WooCommerce is present.
	 *
	 * Two stable symbols per SEC-002. `wc_get_product()` is the accessor every repository in this
	 * suite routes through, so its absence would break us — the same reasoning ACF uses for
	 * `acf_get_setting()`.
	 *
	 * The VERSION is deliberately not part of the test. A version-based refusal would take the
	 * diagnostics offline exactly when they are most wanted; `store/get-store-status` reports the
	 * version instead and lets the caller judge.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return defined( 'WC_VERSION' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'store_missing',
				__( 'WooCommerce is not active on this site, so there is no catalogue, no orders and no store configuration to read or change.', 'acrossai-abilities-manager' )
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
	 * A single `current_user_can( $floor )`, and the filter is consulted only after it passes — so a
	 * filter can tighten access and never widen it (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY).
	 *
	 * Worth stating plainly because this tab holds two permission models: WooCommerce's own seven
	 * abilities use `wc_rest_check_post_permissions()` and admit a Shop Manager, while everything
	 * here requires an administrator.
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
			 * Filters whether the current user may use a store ability.
			 *
			 * @since 0.0.34
			 * @param bool   $allowed Whether access is granted. Always true at this point.
			 * @param string $floor   WordPress capability floor.
			 */
			return (bool) apply_filters( self::PERMISSION_FILTER, true, $floor );
		};
	}

	/**
	 * Success envelope.
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
