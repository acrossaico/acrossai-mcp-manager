<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Single point of truth for WordPress's file-modification kill switches.
 *
 * - DISALLOW_FILE_MODS blocks ALL file modifications (installs, updates, edits).
 *   Honoured for every call (any non-'install' context also routes through
 *   wp_is_file_mod_allowed() so the file_mod_allowed filter is respected).
 * - DISALLOW_FILE_EDIT additionally blocks in-place file editing (theme/plugin
 *   file editor). Only checked when $context === 'edit' (the default).
 *
 * DB-only operations are never affected by these constants and must not call
 * this guard.
 *
 * Usage at a helper IO layer (returns WP_Error):
 *     $guard = File_Mods_Guard::check();
 *     if ( is_wp_error( $guard ) ) { return $guard; }
 *
 * Usage at an ability layer (returns a ready-made ability response):
 *     $blocked = File_Mods_Guard::blocked_response();
 *     if ( null !== $blocked ) { return $blocked; }
 */
final class File_Mods_Guard {

	/**
	 * Returns WP_Error when file mods are disallowed, true otherwise.
	 *
	 * @param string $context 'edit' (default) checks both DISALLOW_FILE_MODS and
	 *                        DISALLOW_FILE_EDIT. 'install' only checks
	 *                        DISALLOW_FILE_MODS — pass this for plugin/theme
	 *                        install/upgrade/uninstall flows.
	 * @return true|\WP_Error
	 */
	public static function check( string $context = 'edit' ) {
		if ( ! self::file_mods_allowed( $context ) ) {
			return new \WP_Error(
				'file_mods_disabled',
				__( 'File modifications are disabled on this site (DISALLOW_FILE_MODS is set or blocked by the file_mod_allowed filter). Save to the database instead, or remove the restriction in wp-config.php.', 'acrossai-abilities-manager' )
			);
		}

		if ( 'edit' === $context && defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return new \WP_Error(
				'file_edit_disabled',
				__( 'In-place file editing is disabled on this site (DISALLOW_FILE_EDIT is set). Save to the database instead, or remove the constant in wp-config.php.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Convenience for ability classes — returns a ready-made failure response
	 * when file mods are blocked, or null when the caller may proceed.
	 *
	 * Returns the standard {success, message} envelope used everywhere else in
	 * the plugin; the WP_Error code is intentionally omitted so this shape
	 * stays valid under the abilities' `additionalProperties: false` schemas.
	 *
	 * @return array{success: false, message: string}|null
	 */
	public static function blocked_response( string $context = 'edit' ): ?array {
		$check = self::check( $context );
		if ( ! is_wp_error( $check ) ) {
			return null;
		}
		return array(
			'success' => false,
			'message' => $check->get_error_message(),
		);
	}

	/**
	 * Wraps wp_is_file_mod_allowed() when available; falls back to the raw
	 * DISALLOW_FILE_MODS constant during very early load.
	 */
	private static function file_mods_allowed( string $context ): bool {
		if ( function_exists( 'wp_is_file_mod_allowed' ) ) {
			return (bool) wp_is_file_mod_allowed( 'acrossai_core_abilities_' . $context );
		}
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}
}
