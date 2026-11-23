<?php
/**
 * Feature 116 — availability, permission and envelope for the Loco Translate suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this suite decides whether it may run, and the one shape it answers in.
 *
 * @since 0.0.34
 */
final class Loco_Guard {

	/**
	 * Filter name for the permission decision.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_loco_permission';

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether Loco Translate is loaded.
	 *
	 * Two classes rather than the plugin constant: these are the ones the suite actually calls, so
	 * their absence is what would break it. `Loco_package_Bundle` is the discovery root and
	 * `Loco_gettext_Compiler` is the only sanctioned write path.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'Loco_package_Bundle' ) && class_exists( 'Loco_gettext_Compiler' );
	}

	/**
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'loco_translate_missing',
				__( 'Loco Translate is not active on this site, so there are no translation bundles to read or change.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Whether this WordPress writes the `.l10n.php` translation cache.
	 *
	 * WordPress 6.5 added it and reads it IN PREFERENCE to the `.mo`. On such a site a save that
	 * produced a `.mo` but no `.l10n.php` leaves the old strings rendering, so a zero byte count for
	 * that artefact means two different things depending on this answer — correct on 6.4, a failure
	 * on 6.5+. Every report that carries `php_bytes` carries this alongside it.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function uses_php_cache(): bool {
		return class_exists( 'WP_Translation_File_PHP', false );
	}

	/**
	 * Validate a locale tag through Loco's own parser.
	 *
	 * Refusing here rather than passing a free string on: an unparseable tag would otherwise become a
	 * real file on disk — `wp-content/languages/plugins/foo-nonsense.po` — that nothing ever loads
	 * and nobody goes looking for.
	 *
	 * @since  0.0.34
	 * @param  string $tag Locale tag such as de_DE.
	 * @return object|WP_Error Loco_Locale on success.
	 */
	public static function parse_locale( string $tag ) {
		$tag = trim( $tag );

		if ( '' === $tag ) {
			return new WP_Error(
				'invalid_locale',
				__( 'A locale is required, for example de_DE or fr_FR.', 'acrossai-abilities-manager' )
			);
		}

		$locale = \Loco_Locale::parse( $tag );

		if ( ! $locale instanceof \Loco_Locale || ! $locale->isValid() ) {
			return new WP_Error(
				'invalid_locale',
				sprintf(
					/* translators: %s: the locale tag supplied. */
					__( '"%s" is not a locale Loco can parse. Use a WordPress locale such as de_DE, fr_FR or pt_BR; call translations/list-locales to see what this site already has.', 'acrossai-abilities-manager' ),
					$tag
				)
			);
		}

		return $locale;
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
	 * Loco's own capability is `loco_admin`, and it creates a `translator` role. Neither is used as
	 * the floor here: a translator editing strings through wp-admin is a different risk from an AI
	 * client writing files that execute nowhere but render everywhere.
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
			 * Filters whether the current user may use a Loco Translate ability.
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
