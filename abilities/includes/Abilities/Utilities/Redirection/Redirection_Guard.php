<?php
/**
 * Feature 130 — shared guard, permission factory and response envelope for the
 * Redirection ability suite.
 *
 * Every \Red_* symbol used by the suite is reached through this directory. No
 * ability class may name one directly — Test_Redirection_* asserts that.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Redirection
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Redirection;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only guard + envelope helpers for the Redirection suite.
 *
 * Redirection ships no abilities of its own and keeps its redirects, groups and
 * logs in its own tables, reachable only through its model layer. Each reader is
 * wrapped here and degrades to a WP_Error rather than fatally dereferencing a
 * class that moved; Redirection's model shapes are not a public contract.
 */
final class Redirection_Guard {

	/**
	 * The Redirection plugin file, as registered with WordPress.
	 */
	public const PLUGIN_FILE = 'redirection/redirection.php';

	/**
	 * Filter name allowing site owners to relax the capability policy.
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_redirection_permission';

	/**
	 * Private constructor — this class is static-only.
	 */
	private function __construct() {}

	/**
	 * Whether Redirection is loaded this request.
	 *
	 * Detects by Red_Item, the model every ability reaches through, which
	 * Redirection requires unconditionally when it boots — never by the
	 * plugin-active option, which can disagree with what is actually loaded.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( '\Red_Item' );
	}

	/**
	 * Assert Redirection is loaded.
	 *
	 * Called first by every execute() as defence in depth: the bootstrap already
	 * gates registration, but the plugin can be deactivated after the abilities
	 * were registered in the same request.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_active() ) {
			return new WP_Error(
				'redirection_missing',
				__( 'The Redirection plugin is not active on this site. Install and activate it to use this ability.', 'acrossai-abilities-manager' )
			);
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
	 * Build the permission_callback for an ability.
	 *
	 * Redirection's own default capability is manage_options
	 * (Redirection_Capabilities::CAP_DEFAULT), matching the convention across the
	 * rest of includes/Abilities/. A single filter governs the whole suite.
	 *
	 * @param string $floor WordPress capability floor.
	 * @return callable(): bool
	 */
	public static function can( string $floor = 'manage_options' ): callable {
		return static function () use ( $floor ): bool {
			$allowed = current_user_can( $floor );

			/**
			 * Filter whether the current user may execute a Redirection ability.
			 *
			 * @param bool   $allowed Result of the capability floor check.
			 * @param string $floor   WordPress capability floor.
			 */
			return (bool) apply_filters( self::PERMISSION_FILTER, $allowed, $floor );
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
