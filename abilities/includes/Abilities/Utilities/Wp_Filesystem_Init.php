<?php
/**
 * Shared WP_Filesystem bootstrap for FileManager abilities (Feature 091).
 *
 * Every file-manager/* ability that touches the filesystem calls
 * Wp_Filesystem_Init::get() or Wp_Filesystem_Init::blocked_response() at the
 * top of its execute() method. The pair centralises the boilerplate so
 * every ability handles credential-missing / transport-unavailable cases
 * identically.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Utility for the WordPress WP_Filesystem bootstrap.
 *
 * Two static entry points:
 *
 *   // Ability layer — returns ready-made failure response on init failure,
 *   // or null when the caller may proceed.
 *   $blocked = Wp_Filesystem_Init::blocked_response();
 *   if ( null !== $blocked ) { return $blocked; }
 *   $fs = Wp_Filesystem_Init::get(); // guaranteed WP_Filesystem_Base
 *
 *   // Or, if you need the WP_Error yourself:
 *   $fs = Wp_Filesystem_Init::get();
 *   if ( is_wp_error( $fs ) ) { return $fs; }
 */
final class Wp_Filesystem_Init {

	/**
	 * Bootstrap $wp_filesystem for the current request.
	 *
	 * Idempotent — re-uses the global $wp_filesystem when it is already
	 * a WP_Filesystem_Base instance.
	 *
	 * @return \WP_Filesystem_Base|\WP_Error Filesystem object on success,
	 *   WP_Error(code=filesystem_unavailable) when WP_Filesystem() fails.
	 */
	public static function get() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// WP_Filesystem() returns true on success, false when init fails
		// (e.g. missing FTP credentials on a non-direct transport).
		if ( ! WP_Filesystem() || ! ( $wp_filesystem instanceof \WP_Filesystem_Base ) ) {
			return new \WP_Error(
				'filesystem_unavailable',
				__(
					'WordPress could not initialise its filesystem transport. On hosts that require FTP or SSH credentials, ensure FTP_HOST / FTP_USER / FTP_PASS (or the equivalent) are defined in wp-config.php.',
					'acrossai-abilities-manager'
				)
			);
		}

		return $wp_filesystem;
	}

	/**
	 * Convenience for ability classes — returns a ready-made failure
	 * response when the filesystem cannot be initialised, or null when
	 * the caller may proceed with WP_Filesystem_Base.
	 *
	 * Returns the standard {success, blocked_reason, message} envelope
	 * used everywhere else in the plugin. Matches the pattern of
	 * File_Mods_Guard::blocked_response().
	 *
	 * @return array{success: false, blocked_reason: string, message: string}|null
	 */
	public static function blocked_response(): ?array {
		$fs = self::get();
		if ( ! is_wp_error( $fs ) ) {
			return null;
		}
		return array(
			'success'        => false,
			'blocked_reason' => 'filesystem_unavailable',
			'message'        => (string) $fs->get_error_message(),
		);
	}
}
