<?php
/**
 * Feature 103 — shared guards, permission factory and response envelope for the
 * Contact Form 7 ability suite.
 *
 * Every `WPCF7_*` symbol used by the suite is reached through this directory. No ability class may
 * name one directly — Test_Contact_Form_7_Architecture asserts that.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only guard + envelope helpers.
 */
final class Contact_Form_7_Guard {

	/**
	 * Filter name allowing site owners to relax the capability policy.
	 *
	 * Evaluated inside can() so a single filter governs every ability in the suite.
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_contact_form_7_permission';

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether Contact Form 7 is loaded.
	 *
	 * `WPCF7_ContactForm` rather than the `WPCF7_VERSION` constant: it is the class every ability
	 * ultimately reaches through, so its presence also proves CF7's autoloading is live.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * Assert Contact Form 7 is loaded.
	 *
	 * Called first by every execute() as defence in depth: the bootstrap already gates instantiation,
	 * but CF7 can be deactivated after the abilities were registered in the same request.
	 *
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'contact_form_7_missing',
				__( 'Contact Form 7 is not active.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Assert the caller confirmed an irreversible operation.
	 *
	 * @since  0.0.34
	 * @param  array<string,mixed> $input Ability input.
	 * @return true|WP_Error
	 */
	public static function assert_confirmed( array $input ) {
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				__( 'This operation cannot be undone. Pass confirm: true to proceed.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Build the permission_callback for one ability.
	 *
	 * A SINGLE `current_user_can( $floor )` combined with the CF7 capability — never OR-ed with a
	 * default, which would let the filter lower the effective requirement
	 * (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY).
	 *
	 * The floor is `manage_options` for every ability in this suite and is declared `final` on the
	 * base. CF7 maps its own capabilities onto `publish_pages` / `edit_posts`, so gating on the CF7
	 * capability alone would let an Editor drive these abilities. That is the exact shape that opened
	 * a hole in the Rank Math suite when its floor was lowered — see
	 * Base_Rank_Math_Ability::permission_floor().
	 *
	 * @since  0.0.34
	 * @param  string $cf7_cap CF7 capability, without the `wpcf7_` prefix. '' skips the check.
	 * @param  string $floor   WordPress capability floor.
	 * @return callable
	 */
	public static function can( string $cf7_cap, string $floor = 'manage_options' ): callable {
		return static function () use ( $cf7_cap, $floor ): bool {
			if ( ! current_user_can( $floor ) || ! self::has_cap( $cf7_cap ) ) {
				return false;
			}

			/**
			 * Filters whether the current user may use a Contact Form 7 ability.
			 *
			 * Consulted only for a user who already clears both the floor and the granular CF7
			 * capability, so it can deny but never grant (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY).
			 * This suite deliberately guards harder than Contact Form 7 guards itself — an Editor
			 * who can manage forms in wp-admin cannot manage them through an ability, because an
			 * ability is reachable by an AI client and wp-admin is not. A filter that could widen
			 * would hand that decision to any other plugin on the site.
			 *
			 * @since 0.0.34
			 * @param bool   $allowed Whether access is granted. Always true at this point.
			 * @param string $cf7_cap CF7 capability suffix.
			 * @param string $floor   WordPress capability floor.
			 */
			return (bool) apply_filters( self::PERMISSION_FILTER, true, $cf7_cap, $floor );
		};
	}

	/**
	 * Whether the current user holds a CF7 capability.
	 *
	 * CF7's capabilities are `define`-overridable by site owners
	 * (`WPCF7_ADMIN_READ_WRITE_CAPABILITY` / `WPCF7_ADMIN_READ_CAPABILITY`), so the `wpcf7_*` cap is
	 * checked rather than the primitive it currently maps to.
	 *
	 * @since  0.0.34
	 * @param  string $cap Capability suffix, e.g. `edit_contact_forms`.
	 * @return bool
	 */
	public static function has_cap( string $cap ): bool {
		if ( '' === $cap ) {
			return true;
		}

		return current_user_can( 'wpcf7_' . str_replace( '-', '_', $cap ) );
	}

	/**
	 * Whether the current user may edit one specific form.
	 *
	 * Per-object defence in depth, used inside run() by the writers. The permission_callback cannot
	 * do this: it receives no input, so it cannot know which form is being addressed.
	 *
	 * @since  0.0.34
	 * @param  int $form_id Form post ID.
	 * @return true|WP_Error
	 */
	public static function assert_can_edit( int $form_id ) {
		if ( ! current_user_can( 'wpcf7_edit_contact_form', $form_id ) ) {
			return new WP_Error(
				'insufficient_capability',
				__( 'You are not allowed to edit this contact form.', 'acrossai-abilities-manager' )
			);
		}

		return true;
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
	 * @param  WP_Error            $error   Error to unwrap.
	 * @param  array<string,mixed> $context Optional identifying input to echo back.
	 * @return array<string,mixed>
	 */
	public static function fail( WP_Error $error, array $context = array() ): array {
		return self::error( (string) $error->get_error_code(), (string) $error->get_error_message(), $context );
	}

	/**
	 * Build a failure envelope from an explicit code and message.
	 *
	 * @since  0.0.34
	 * @param  string              $code    Machine-readable error code.
	 * @param  string              $message Human-readable message.
	 * @param  array<string,mixed> $context Optional identifying input to echo back.
	 * @return array<string,mixed>
	 */
	public static function error( string $code, string $message, array $context = array() ): array {
		// Context often echoes caller-supplied input back. Strip the reserved envelope keys so a
		// caller can never spoof success or the error code.
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
