<?php
/**
 * Feature 118 — availability, account state, permission and envelope for the consent suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Consent
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Consent;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this suite decides whether it may run, and the one shape it answers in.
 *
 * @since 0.0.34
 */
final class Consent_Guard {

	/**
	 * Filter name for the permission decision.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_consent_permission';

	/**
	 * Settings option.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const SETTINGS_OPTION = 'cky_settings';

	/**
	 * Rendered banner HTML, keyed by language. The thing a visitor actually sees.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TEMPLATE_OPTION = 'cky_banner_template';

	/**
	 * Settings keys that are credentials and are never returned.
	 *
	 * The Feature 106 lesson about stored OAuth tokens, applied before it can bite: these sit in the
	 * same option as ordinary settings, so a reader that returns the option wholesale hands them out.
	 *
	 * @since 0.0.34
	 * @var   array<string, string[]>
	 */
	public const REDACTED = array(
		'api'     => array( 'token' ),
		'account' => array( 'website_key', 'website_id' ),
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether the consent plugin is present.
	 *
	 * Two stable symbols per SEC-002: a single class test is spoofable by anything defining the same
	 * name. The controller is what this suite actually calls, and `cky_selected_languages()` is what
	 * every multilingual field is built from, so both absences would break us.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( '\CookieYes\Lite\Admin\Modules\Cookies\Includes\Cookie_Controller' )
			&& function_exists( 'cky_selected_languages' );
	}

	/**
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'consent_plugin_missing',
				__( 'The cookie consent plugin is not active on this site, so there is no banner, no cookie inventory and no consent categories to read or change.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Settings, as stored.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether the site is linked to the vendor's account.
	 *
	 * Read from the stored setting rather than from `CKY_CLOUD_REQUEST`, even though the plugin
	 * defines that constant from this same value. The constant is defined ONCE at boot
	 * (`lite/includes/class-cli.php:112-124`), so on a request that began before a connection it
	 * would answer for the state the request started in. The option is the truth.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_connected(): bool {
		$settings = self::settings();

		return isset( $settings['account']['connected'] ) && true === $settings['account']['connected'];
	}

	/**
	 * Where a human goes to link the account.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public static function connect_url(): string {
		return admin_url( 'admin.php?page=cookie-law-info' );
	}

	/**
	 * Refuse an ability that needs the account, and say what to do about it.
	 *
	 * Connecting authorises an external account, so nothing here can perform it. The message names
	 * the screen and the button and asks the caller to report back once it is done — an instruction
	 * rather than a dead end, because an assistant handed a vague failure either gives up or
	 * silently retries the same call. The URL is on the error data as well, for machine use.
	 *
	 * @since  0.0.34
	 * @param  string $what Human-readable name of the thing that needs the account.
	 * @return true|WP_Error
	 */
	public static function assert_connected( string $what = '' ) {
		if ( self::is_connected() ) {
			return true;
		}

		$subject = '' !== $what ? $what : __( 'This information', 'acrossai-abilities-manager' );

		return new WP_Error(
			'consent_account_not_connected',
			sprintf(
				/* translators: 1: what needs the account, 2: admin URL. */
				__( '%1$s is produced by the consent service and is only available once this site is linked to a consent account. Linking authorises an external account, so it has to be done by a person: open %2$s and use the Connect button, then tell me once it is done and I will try again. Everything else in this toolset — the cookie list, the categories and the banner — works without it.', 'acrossai-abilities-manager' ),
				$subject,
				self::connect_url()
			),
			array(
				'connect_url' => self::connect_url(),
				'connected'   => false,
			)
		);
	}

	/**
	 * Strip credentials from a settings array before it is returned.
	 *
	 * Reports what it removed rather than removing it silently, so a caller can tell the difference
	 * between "this setting is empty" and "you may not see this".
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $settings Settings to clean.
	 * @return array{settings: array<string, mixed>, redacted: string[]}
	 */
	public static function redact( array $settings ): array {
		$removed = array();

		foreach ( self::REDACTED as $group => $keys ) {
			foreach ( $keys as $key ) {
				if ( ! isset( $settings[ $group ][ $key ] ) ) {
					continue;
				}

				if ( '' === (string) $settings[ $group ][ $key ] ) {
					continue;
				}

				$settings[ $group ][ $key ] = '';
				$removed[]                  = $group . '.' . $key;
			}
		}

		return array(
			'settings' => $settings,
			'redacted' => $removed,
		);
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
			 * Filters whether the current user may use a consent ability.
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
		$data = $error->get_error_data();

		$out = self::error( (string) $error->get_error_code(), (string) $error->get_error_message() );

		if ( is_array( $data ) && isset( $data['connect_url'] ) ) {
			$out['connect_url'] = (string) $data['connect_url'];
			$out['connected']   = false;
		}

		return $out;
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
