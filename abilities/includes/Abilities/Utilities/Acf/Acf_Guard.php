<?php
/**
 * Feature 105 — shared guards, edition detection, permission factory and envelope for the ACF suite.
 *
 * Every ACF symbol used by the suite is reached through this directory. No ability class may name one
 * directly — Test_Acf_Architecture asserts that.
 *
 * **The two editions are mutually exclusive.** ACF Pro is a replacement for free ACF, not an add-on:
 * same `acf.php`, same `ACF_VERSION`, same function set, plus four extra field types and blocks.
 * WordPress refuses to run both and auto-deactivates one. So there is never a both-active state to
 * reconcile — the question is only "is ACF running, and if so which edition", which is what the two
 * probes below answer.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only guard + envelope helpers.
 */
final class Acf_Guard {

	/**
	 * Filter name allowing site owners to relax the capability policy.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_acf_permission';

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether ACF — either edition — is loaded.
	 *
	 * Compound check per SEC-002: `ACF_VERSION` alone is a constant any plugin could define, and
	 * `acf_get_setting()` alone is a function name that could collide. Together they are the same
	 * predicate `Integrations\ACF::is_plugin_active()` uses, kept in step deliberately.
	 *
	 * Deliberately does NOT look at the filesystem. A development machine commonly has both the free
	 * and Pro directories present, and that says nothing about which one is running.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return defined( 'ACF_VERSION' ) && function_exists( 'acf_get_setting' );
	}

	/**
	 * Whether the running edition is ACF Pro.
	 *
	 * Asks ACF rather than inferring. `acf_is_pro()` has shipped in BOTH editions since 6.2
	 * (`includes/acf-helper-functions.php`) and returns `defined( 'ACF_PRO' ) && ACF_PRO`; Pro sets
	 * that at `pro/acf-pro.php` via `acf()->define( 'ACF_PRO', true )` and free never does. Going
	 * through the helper keeps this correct if ACF ever changes how it marks the edition.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_pro(): bool {
		return self::is_available() && function_exists( 'acf_is_pro' ) && \acf_is_pro();
	}

	/**
	 * Whether a field type is registered on this install.
	 *
	 * This — not `function_exists()` — is the correct gate for the repeater and flexible-content
	 * abilities. `add_row()`, `update_row()` and `delete_row()` ship in `includes/api/api-template.php`
	 * in BOTH editions, so a function check passes on free ACF, where `repeater` and
	 * `flexible_content` are not registered types and no such field can exist. Gating on the function
	 * would advertise abilities in the MCP tool list that can never succeed.
	 *
	 * It also covers the case a field type is removed by filter rather than by edition.
	 *
	 * @since  0.0.34
	 * @param  string $type Field type name, e.g. `repeater`.
	 * @return bool
	 */
	public static function has_field_type( string $type ): bool {
		return self::is_available() && function_exists( 'acf_get_field_type' ) && null !== \acf_get_field_type( $type );
	}

	/**
	 * Whether ACF blocks are available. Pro only.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function has_blocks(): bool {
		return self::is_pro() && function_exists( 'acf_register_block_type' );
	}

	/**
	 * Assert ACF is loaded.
	 *
	 * Called first by every execute() as defence in depth: the bootstrap gates instantiation, but the
	 * edition can be switched — or ACF deactivated — after the abilities were registered in the same
	 * request.
	 *
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'acf_missing',
				__( 'Advanced Custom Fields is not active.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Assert the running edition is ACF Pro.
	 *
	 * @since  0.0.34
	 * @param  string $feature What the caller was trying to do, named in the message.
	 * @return true|WP_Error
	 */
	public static function assert_pro( string $feature ) {
		if ( ! self::is_pro() ) {
			return new WP_Error(
				'acf_pro_required',
				sprintf(
					/* translators: %s: the feature being attempted */
					__( '%s requires Advanced Custom Fields PRO; this site is running the free edition.', 'acrossai-abilities-manager' ),
					$feature
				)
			);
		}

		return true;
	}

	/**
	 * Assert a field type exists before operating on it.
	 *
	 * @since  0.0.34
	 * @param  string $type Field type name.
	 * @return true|WP_Error
	 */
	public static function assert_field_type( string $type ) {
		if ( ! self::has_field_type( $type ) ) {
			return new WP_Error(
				'acf_field_type_unavailable',
				sprintf(
					/* translators: %s: field type name */
					__( 'The "%s" field type is not available on this site. It is an Advanced Custom Fields PRO field type.', 'acrossai-abilities-manager' ),
					$type
				)
			);
		}

		return true;
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
	 * A SINGLE `current_user_can( $floor )`, never OR-ed with a default, so the filter can only ever
	 * raise the requirement (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY).
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
			 * Filters whether the current user may use an ACF ability.
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
