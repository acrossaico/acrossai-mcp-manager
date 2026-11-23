<?php
/**
 * Feature 132 — availability, permission and envelope for the All in One SEO suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this suite decides whether it may run, and the one shape it answers in.
 *
 * @since 0.0.44
 */
final class Aioseo_Guard {

	/**
	 * @since 0.0.44
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_aioseo_permission';

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether All in One SEO is present and usable.
	 *
	 * Two symbols, and both are load-bearing. `AIOSEO_VERSION` is defined by the free and Pro
	 * editions in the plugin header file, which runs before the `aioseo()` container is built — so the
	 * constant alone reports the plugin usable while every read in this suite would hit a null
	 * container. Must stay identical to `Integrations\All_In_One_Seo::is_active()`, or the toolset and
	 * the abilities disagree about what "active" means.
	 *
	 * @since  0.0.44
	 * @return bool
	 */
	public static function is_available(): bool {
		return defined( 'AIOSEO_VERSION' ) && function_exists( 'aioseo' ) && is_object( aioseo() );
	}

	/**
	 * @since  0.0.44
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'aioseo_missing',
				__( 'All in One SEO is not active on this site, so there are no AIOSEO settings to read or write. Install and activate it, or use the abilities for whichever SEO plugin this site does run.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Assert the AIOSEO Pro edition.
	 *
	 * Used only where a genuinely Pro-only surface is reached. Most of this suite is free-core, and an
	 * ability that could simply report "not available" should do that instead of refusing.
	 *
	 * @since  0.0.44
	 * @return true|WP_Error
	 */
	public static function assert_pro() {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		if ( empty( aioseo()->pro ) ) {
			return new WP_Error(
				'aioseo_pro_required',
				__( 'This operation needs the All in One SEO Pro edition, and this site runs the free one.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * @since  0.0.44
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
	 * A WordPress floor first, then AIOSEO's own capability for the area, then the filter — which is
	 * consulted only after both have passed, so a filter can tighten access and never widen it
	 * (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY).
	 *
	 * The AIOSEO capability is asked through `aioseo()->access->hasCapability()` rather than
	 * `current_user_can( 'aioseo_…' )`. AIOSEO maintains its own role matrix and that method is what
	 * its own REST routes consult; going through WordPress directly would answer differently for any
	 * role whose AIOSEO access was changed from its default.
	 *
	 * Pass '' for an area AIOSEO itself gates on the WordPress capability alone.
	 *
	 * @since  0.0.44
	 * @param  string $aioseo_cap AIOSEO capability, e.g. 'aioseo_sitemap_settings'. '' to skip.
	 * @param  string $floor      WordPress capability floor.
	 * @return callable
	 */
	public static function can( string $aioseo_cap = '', string $floor = 'manage_options' ): callable {
		return static function () use ( $aioseo_cap, $floor ): bool {
			if ( ! current_user_can( $floor ) ) {
				return false;
			}

			if ( '' !== $aioseo_cap && self::is_available() && ! self::has_capability( $aioseo_cap ) ) {
				return false;
			}

			/**
			 * Filters whether the current user may use an All in One SEO ability.
			 *
			 * @since 0.0.44
			 * @param bool   $allowed    Whether access is granted. Always true at this point.
			 * @param string $aioseo_cap AIOSEO capability that was required, or ''.
			 * @param string $floor      WordPress capability floor.
			 */
			return (bool) apply_filters( self::PERMISSION_FILTER, true, $aioseo_cap, $floor );
		};
	}

	/**
	 * Whether the current user holds one AIOSEO capability.
	 *
	 * Wrapped because `aioseo()->access` is absent on a site where the container exists but has not
	 * finished building, and a missing accessor must read as "no" rather than fatal.
	 *
	 * @since  0.0.44
	 * @param  string $aioseo_cap AIOSEO capability name.
	 * @return bool
	 */
	public static function has_capability( string $aioseo_cap ): bool {
		if ( '' === $aioseo_cap ) {
			return true;
		}

		if ( ! self::is_available() || ! isset( aioseo()->access ) || ! is_object( aioseo()->access ) ) {
			return false;
		}

		return (bool) aioseo()->access->hasCapability( $aioseo_cap );
	}

	/**
	 * Assert one AIOSEO capability inside run(), for an ability whose required area depends on input.
	 *
	 * @since  0.0.44
	 * @param  string $aioseo_cap AIOSEO capability name.
	 * @return true|WP_Error
	 */
	public static function assert_capability( string $aioseo_cap ) {
		if ( self::has_capability( $aioseo_cap ) ) {
			return true;
		}

		return new WP_Error(
			'aioseo_insufficient_capability',
			sprintf(
				/* translators: %s: AIOSEO capability name */
				__( 'Your role does not hold the All in One SEO "%s" capability, which this area requires. AIOSEO keeps its own role matrix under its Access Control settings, separate from WordPress roles.', 'acrossai-abilities-manager' ),
				$aioseo_cap
			)
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $payload Ability-specific keys.
	 * @param  string               $message Human-readable summary.
	 * @return array<string, mixed>
	 */
	public static function ok( array $payload, string $message ): array {
		unset( $payload['success'], $payload['error_code'], $payload['message'] );

		return array_merge( array( 'success' => true ), $payload, array( 'message' => $message ) );
	}

	/**
	 * @since  0.0.44
	 * @param  WP_Error $error Error.
	 * @return array<string, mixed>
	 */
	public static function fail( WP_Error $error ): array {
		return self::error( (string) $error->get_error_code(), (string) $error->get_error_message() );
	}

	/**
	 * @since  0.0.44
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
