<?php
/**
 * Feature 119 — availability, permission and envelope for the email delivery suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Email
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Email;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this suite decides whether it may run, and the one shape it answers in.
 *
 * @since 0.0.34
 */
final class Email_Guard {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_email_permission';

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	public const OPTION = 'wp_mail_smtp';

	/**
	 * Credential keys, per mailer group.
	 *
	 * Sourced from `src/Options.php:42-97`. These are never returned, and never READ through the
	 * plugin's own accessor: `Options::get()` ends in `Crypto::decrypt()` (Options.php:439), so
	 * asking it for one of these hands back a plaintext password. Presence is judged from the raw
	 * stored value instead, so no decrypt ever happens.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const CREDENTIAL_KEYS = array( 'pass', 'api_key', 'client_secret', 'client_id', 'auth_token', 'access_token', 'refresh_token', 'private_key' );

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether WP Mail SMTP is present.
	 *
	 * Two stable symbols per SEC-002. `wp_mail_smtp()` is the plugin's own accessor and `Options` is
	 * the class this suite reads through, so either absence would break us.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_mail_smtp' ) && class_exists( '\WPMailSMTP\Options' );
	}

	/**
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'mail_plugin_missing',
				__( 'The email delivery plugin is not active on this site, so there is no mailer configuration to read and no way to send a test message through it.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * The raw stored option, undecrypted.
	 *
	 * Deliberately `get_option()` and not the plugin's accessor. See {@see self::CREDENTIAL_KEYS}.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function raw_options(): array {
		$options = get_option( self::OPTION, array() );

		return is_array( $options ) ? $options : array();
	}

	/**
	 * Whether a key is a credential.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return bool
	 */
	public static function is_credential( string $key ): bool {
		return in_array( $key, self::CREDENTIAL_KEYS, true );
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
			 * Filters whether the current user may use an email delivery ability.
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
