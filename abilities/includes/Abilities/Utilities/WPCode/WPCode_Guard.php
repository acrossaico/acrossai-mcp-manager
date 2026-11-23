<?php
/**
 * Feature 112 — availability, permission and envelope for the WPCode suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this suite decides whether it may run, and the one shape it answers in.
 *
 * @since 0.0.34
 */
final class WPCode_Guard {

	/**
	 * Filter name for the permission decision.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_wpcode_permission';

	/**
	 * Code types WPCode actually executes as code.
	 *
	 * These are the two `run_activation_checks()` test-runs before allowing activation
	 * (class-wpcode-snippet.php:657). Everything else is emitted, not executed.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const EXECUTED_TYPES = array( 'php', 'universal' );

	/**
	 * Every code type WPCode supports, from includes/execute/.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const CODE_TYPES = array( 'php', 'js', 'css', 'html', 'text', 'universal' );

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether WPCode is present.
	 *
	 * The class rather than a constant: WPCode Lite and Pro both define `WPCODE_VERSION` but the
	 * snippet class is what this suite actually calls, so its absence is what would break us.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'WPCode_Snippet' ) && function_exists( 'wpcode' );
	}

	/**
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wpcode_missing',
				__( 'The WPCode plugin is not active on this site, so there are no snippets to read or change.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Whether the site has switched PHP snippets off entirely.
	 *
	 * WPCode ships `completely_disable_php` as an explicit site policy. Writing a PHP snippet while
	 * it is on would override a decision the site owner already made, so every PHP write refuses.
	 * This is correctness, not caution: the snippet would never run either way.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function php_disabled(): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		return (bool) wpcode()->settings->get_option( 'completely_disable_php' );
	}

	/**
	 * Whether safe mode is suppressing every snippet on this request.
	 *
	 * With safe mode on nothing executes at all, so a snippet saved and activated now is still inert.
	 * Readers report it; writers say so rather than implying the change took effect.
	 *
	 * WPCode exposes no `is_safe_mode()` helper, so the condition is mirrored from
	 * `wpcode_maybe_prevent_execution()` (safe-mode.php:95): the query parameter only has to be
	 * PRESENT — its value is never read — and it only suppresses execution for a user who could
	 * activate snippets anyway. Testing for `=== '1'` would report safe mode as off for the
	 * `?wpcode-safe-mode` and `?wpcode-safe-mode=yes` forms that do in fact enable it.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function safe_mode(): bool {
		if ( ! isset( $_GET['wpcode-safe-mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		return current_user_can( 'wpcode_activate_snippets' )
			|| ( function_exists( 'wpcode_is_wplogin' ) && wpcode_is_wplogin() );
	}

	/**
	 * @since  0.0.34
	 * @param  string $code_type Code type to check.
	 * @return true|WP_Error
	 */
	public static function assert_code_type( string $code_type ) {
		if ( ! in_array( $code_type, self::CODE_TYPES, true ) ) {
			return new WP_Error(
				'invalid_code_type',
				sprintf(
					/* translators: 1: supplied code type, 2: accepted list. */
					__( '"%1$s" is not a WPCode code type. Accepted types are: %2$s.', 'acrossai-abilities-manager' ),
					$code_type,
					implode( ', ', self::CODE_TYPES )
				)
			);
		}

		if ( in_array( $code_type, self::EXECUTED_TYPES, true ) && self::php_disabled() ) {
			return new WP_Error(
				'php_disabled',
				__( 'This site has WPCode\'s "Completely Disable PHP Snippets" setting switched on, so PHP and Universal snippets cannot be written or activated. Turn that setting off first if this is intended.', 'acrossai-abilities-manager' )
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
			 * Filters whether the current user may use a WPCode ability.
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
	 * `success` and `message` are set last so a payload key cannot spoof them.
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
