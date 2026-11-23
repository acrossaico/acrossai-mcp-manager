<?php
/**
 * Feature 069 — shared guards, permission factory and response envelope for the
 * Rank Math ability suite.
 *
 * Every \RankMath\* symbol used by the suite is reached through this directory.
 * No ability class may name one directly — Test_Rank_Math_* asserts that.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only guard + envelope helpers.
 *
 * Three distinct gate flavours exist deliberately (Feature 069 research F7):
 * Rank Math PRO is a separate plugin, while Content AI and AI Visibility ship in
 * free core and gate on cloud-account registration plus a credit balance. A
 * single "is pro" check would be wrong for all three.
 */
final class Rank_Math_Guard {

	/**
	 * Filter name allowing site owners to relax the capability policy.
	 *
	 * Evaluated inside can() so a single filter governs every ability in the
	 * suite. See specs/069-rank-math-abilities/quickstart.md for a worked
	 * example.
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_rank_math_permission';

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Assert Rank Math is loaded.
	 *
	 * Called first by every execute() as defence in depth: the bootstrap already
	 * gates instantiation, but Rank Math can be deactivated after the abilities
	 * were registered in the same request.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! class_exists( '\RankMath\Helper' ) ) {
			return new WP_Error( 'rank_math_missing', __( 'Rank Math SEO is not active.', 'acrossai-abilities-manager' ) );
		}
		return true;
	}

	/**
	 * Assert a named Rank Math module is active.
	 *
	 * @param string $module Module id as listed in RankMath\Module\Manager — e.g. 'redirections', '404-monitor', 'sitemap'.
	 * @return true|WP_Error
	 */
	public static function assert_module( string $module ) {
		if ( '' === $module ) {
			return true;
		}
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		if ( ! \RankMath\Helper::is_module_active( $module ) ) {
			return new WP_Error(
				'rank_math_module_inactive',
				sprintf(
					/* translators: %s: Rank Math module id */
					__( 'The Rank Math "%s" module is not active. Enable it with rank-math/set-module-status.', 'acrossai-abilities-manager' ),
					$module
				)
			);
		}
		return true;
	}

	/**
	 * Assert the Rank Math PRO plugin is installed.
	 *
	 * Used only where a genuinely PRO-only class is referenced. Content AI and
	 * AI Visibility are NOT PRO-gated — use assert_account() for those.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_pro() {
		if ( ! defined( 'RANK_MATH_PRO_VERSION' ) && ! class_exists( '\RankMathPro\Plugin' ) ) {
			return new WP_Error( 'rank_math_pro_required', __( 'This operation requires Rank Math PRO.', 'acrossai-abilities-manager' ) );
		}
		return true;
	}

	/**
	 * Assert a Rank Math cloud account is connected.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_account() {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		if ( ! class_exists( '\RankMath\Admin\Admin_Helper' ) ) {
			return new WP_Error( 'rank_math_account_required', __( 'This operation requires a connected Rank Math account.', 'acrossai-abilities-manager' ) );
		}
		$registration = \RankMath\Admin\Admin_Helper::get_registration_data();
		if ( empty( $registration ) ) {
			return new WP_Error( 'rank_math_account_required', __( 'This operation requires a connected Rank Math account. Connect the site at Rank Math → Dashboard.', 'acrossai-abilities-manager' ) );
		}
		return true;
	}

	/**
	 * Assert at least $min Content AI credits remain.
	 *
	 * Called BEFORE any remote request so a zero balance never costs a
	 * round-trip (Feature 069 FR-014).
	 *
	 * @param int $min Minimum credits required.
	 * @return true|WP_Error
	 */
	public static function assert_credits( int $min = 1 ) {
		$account = self::assert_account();
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		$credits = (int) \RankMath\Helper::get_credits();
		if ( $credits < $min ) {
			return new WP_Error(
				'content_ai_no_credits',
				sprintf(
					/* translators: 1: credits required, 2: credits available */
					__( 'Insufficient Content AI credits: %1$d required, %2$d available. No request was made.', 'acrossai-abilities-manager' ),
					$min,
					$credits
				)
			);
		}
		return true;
	}

	/**
	 * Assert Google Search Console is connected and authorised.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_console() {
		$module = self::assert_module( 'analytics' );
		if ( is_wp_error( $module ) ) {
			return $module;
		}
		if ( ! class_exists( '\RankMath\Google\Authentication' ) || ! class_exists( '\RankMath\Google\Console' ) ) {
			return new WP_Error( 'google_console_not_connected', __( 'Google Search Console is not connected.', 'acrossai-abilities-manager' ) );
		}
		if ( ! \RankMath\Google\Authentication::is_authorized() || ! \RankMath\Google\Console::is_console_connected() ) {
			return new WP_Error( 'google_console_not_connected', __( 'Google Search Console is not connected. Connect it at Rank Math → Analytics.', 'acrossai-abilities-manager' ) );
		}
		return true;
	}

	/**
	 * Assert the caller explicitly opted into a destructive operation.
	 *
	 * The message names the flag so a client can retry without guessing.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return true|WP_Error
	 */
	public static function assert_confirmed( array $input ) {
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error( 'confirmation_required', __( 'This operation is irreversible. Pass confirm: true to proceed.', 'acrossai-abilities-manager' ) );
		}
		return true;
	}

	/**
	 * Check a Rank Math granular capability.
	 *
	 * Deliberately re-implements \RankMath\Helper::has_cap() rather than calling
	 * it (same semantics as includes/helpers/class-wordpress.php:54) for two
	 * reasons: it cannot fatal when Rank Math is absent, and it keeps
	 * permission_callback free of cross-plugin coupling.
	 *
	 * The '-' to '_' normalisation matters — the module id is '404-monitor' but
	 * the capability is 'rank_math_404_monitor'.
	 *
	 * @param string $cap Capability suffix, e.g. 'titles', 'redirections', '404-monitor'.
	 * @return bool
	 */
	public static function has_cap( string $cap ): bool {
		if ( '' === $cap ) {
			return true;
		}
		return current_user_can( 'rank_math_' . str_replace( '-', '_', $cap ) );
	}

	/**
	 * Build the permission_callback for an ability.
	 *
	 * Composes the house capability floor with Rank Math's own granular
	 * capability. AND is never looser than either model: it is tighter than a
	 * blanket manage_options (which would ignore the Role Manager grants a site
	 * owner deliberately configured) and tighter than Rank Math's model alone.
	 *
	 * Pass an empty $rm_cap for abilities Rank Math itself gates on
	 * manage_options, where there is no granular capability to compose.
	 *
	 * @param string $rm_cap Rank Math capability suffix, or '' for floor only.
	 * @param string $floor  WordPress capability floor.
	 * @return callable(): bool
	 */
	public static function can( string $rm_cap, string $floor = 'manage_options' ): callable {
		return static function () use ( $rm_cap, $floor ): bool {
			$floor_ok = current_user_can( $floor );
			$cap_ok   = self::has_cap( $rm_cap );
			$allowed  = $floor_ok && $cap_ok;

			/**
			 * Filter whether the current user may execute a Rank Math ability.
			 *
			 * Unlike the other suites' permission filters, this one may WIDEN as well as narrow:
			 * returning true admits Rank Math's own looser model — an editor holding
			 * rank_math_titles but not manage_options. That is the documented purpose and is
			 * preserved.
			 *
			 * What it may not do is admit a user holding neither. The result is bounded below by
			 * "clears the floor, or holds the granular Rank Math capability this ability names", so
			 * a filter can substitute one model for the other but cannot grant access to a
			 * subscriber. The bound is deliberately keyed on `'' !== $rm_cap`: has_cap() returns
			 * true when no granular capability applies, so without that check the bound would be
			 * vacuous for exactly the abilities that have nothing but the floor protecting them.
			 *
			 * @param bool   $allowed Result of floor AND granular capability.
			 * @param string $rm_cap  Rank Math capability suffix, '' when none applies.
			 * @param string $floor   WordPress capability floor.
			 */
			$filtered = (bool) apply_filters( self::PERMISSION_FILTER, $allowed, $rm_cap, $floor );

			return $filtered && ( $floor_ok || ( '' !== $rm_cap && $cap_ok ) );
		};
	}

	/**
	 * Build a success envelope.
	 *
	 * @param array<string,mixed> $payload Ability-specific keys.
	 * @param string              $message Human-readable summary.
	 * @return array<string,mixed>
	 */
	public static function ok( array $payload, string $message ): array {
		unset( $payload['success'], $payload['message'], $payload['error_code'] );
		return array_merge( array( 'success' => true ), $payload, array( 'message' => $message ) );
	}

	/**
	 * Build a failure envelope from a WP_Error.
	 *
	 * A raw WP_Error is never returned from execute() — this is where it is
	 * unwrapped.
	 *
	 * @param WP_Error            $error   Error to unwrap.
	 * @param array<string,mixed> $context Optional identifying input to echo back.
	 * @return array<string,mixed>
	 */
	public static function fail( WP_Error $error, array $context = array() ): array {
		return self::error( (string) $error->get_error_code(), (string) $error->get_error_message(), $context );
	}

	/**
	 * Build a failure envelope from an explicit code and message.
	 *
	 * @param string              $code    Machine-readable error code.
	 * @param string              $message Human-readable message.
	 * @param array<string,mixed> $context Optional identifying input to echo back.
	 * @return array<string,mixed>
	 */
	public static function error( string $code, string $message, array $context = array() ): array {
		// Context often echoes caller-supplied input back. Strip the reserved
		// envelope keys so a caller can never spoof success or the error code.
		unset( $context['success'], $context['message'], $context['error_code'] );

		return array_merge(
			array( 'success' => false ),
			$context,
			array(
				'message'    => $message,
				'error_code' => $code,
			)
		);
	}
}
