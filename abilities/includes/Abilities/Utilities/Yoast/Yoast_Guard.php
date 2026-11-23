<?php
/**
 * Feature 106 — shared guards, permission factory and response envelope for the Yoast SEO suite.
 *
 * Every Yoast symbol used by the suite is reached through this directory. No ability class may name
 * one directly — Test_Yoast_Architecture asserts that, for both the legacy `WPSEO_*` classes and the
 * modern `Yoast\WP\SEO\*` namespaced ones.
 *
 * **This suite deliberately does NOT gate on the environment.** Yoast disables its own abilities
 * whenever `is_production_mode()` is false, because indexables record permalinks and building them
 * on staging bakes in the wrong ones. That reasoning applies to indexables and to nothing else:
 * settings, terms, sitemaps and tools are just as valid on a staging site, and inheriting the gate
 * would make all 62 abilities invisible there. Only the indexable abilities care, and they report an
 * empty table rather than vanishing.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only guard + envelope helpers.
 */
final class Yoast_Guard {

	/**
	 * Filter name allowing site owners to relax the capability policy.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_yoast_permission';

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether Yoast SEO is loaded.
	 *
	 * Compound check per SEC-002: the `WPSEO_VERSION` constant alone is a name any plugin could
	 * define, and `WPSEO_Options` alone is a class name that could collide. Together they also prove
	 * Yoast's autoloading is live, which the constant does not.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return defined( 'WPSEO_VERSION' ) && class_exists( '\WPSEO_Options' );
	}

	/**
	 * Assert Yoast SEO is loaded.
	 *
	 * Called first by every execute() as defence in depth: the bootstrap gates instantiation, but
	 * Yoast can be deactivated after the abilities were registered in the same request.
	 *
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'yoast_missing',
				__( 'Yoast SEO is not active.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Whether Yoast's indexables table has been built.
	 *
	 * Read by the indexable abilities so they can report an empty index rather than failing. NOT a
	 * registration gate — see the class docblock.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function has_indexables(): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'yoast_indexable';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Assert the caller confirmed a destructive operation.
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
	 * A SINGLE `current_user_can( $floor )`, never OR-ed with a default, and the filter runs only
	 * after the floor is cleared — so it can tighten access and never widen it
	 * (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY).
	 *
	 * Yoast defines `wpseo_manage_options`, but it maps to `manage_options` by default and is
	 * `add_cap`-editable, so the floor stands alone rather than composing with something a site owner
	 * may have widened.
	 *
	 * @since  0.0.34
	 * @param  string $floor WordPress capability floor.
	 * @return callable
	 */
	public static function can( string $floor = 'manage_options' ): callable {
		return static function () use ( $floor ): bool {
			$allowed = current_user_can( $floor );

			if ( ! $allowed ) {
				return false;
			}

			/**
			 * Filters whether the current user may use a Yoast SEO ability.
			 *
			 * Consulted only for a user who already clears the floor, so it can deny but never
			 * grant. Returning the filter's value directly would let any plugin on the site hand an
			 * SEO write to a subscriber, which is the opposite of what the filter is for.
			 *
			 * @since 0.0.34
			 * @param bool   $allowed Whether access is granted. Always true at this point.
			 * @param string $floor   WordPress capability floor.
			 */
			return (bool) apply_filters( self::PERMISSION_FILTER, $allowed, $floor );
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
