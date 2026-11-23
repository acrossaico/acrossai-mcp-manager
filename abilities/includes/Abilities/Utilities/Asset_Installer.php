<?php
/**
 * Replacing an installed plugin or theme with a specific published package.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * One way to overwrite an installed asset with a package, used by reinstall and by version
 * targeting.
 *
 * Built on WordPress core's own `Plugin_Upgrader` / `Theme_Upgrader` rather than on a third-party
 * rollback plugin, for three reasons that only became visible by reading core:
 *
 * 1. **Core already has restore-on-failure.** Since 6.3 `WP_Upgrader::run()` moves the existing
 *    copy to `wp-content/upgrade-temp-backup/` and, if the package fails to apply, restores it on
 *    `shutdown` — where it is immune to PHP timeouts. That is the hard part of a rollback, and it
 *    is already written and already tested by core.
 * 2. **It works for assets the directory does not host.** Anything with a package URL can be
 *    applied, so this is not limited to WordPress.org.
 * 3. **It is one HTTP request.** A staged pipeline that performs one step per call can strand a
 *    site mid-flight; everything here happens inside a single `execute()` with a `finally`.
 *
 * Two things core does **not** do on this path, both handled here:
 *
 * - `Plugin_Upgrader::install()` never arms `temp_backup` — only `upgrade()` and `bulk_upgrade()`
 *   do. Without it an overwrite clears the destination with nothing to fall back to, so a package
 *   that downloads but fails to unpack leaves the asset deleted. {@see self::temp_backup_args()}
 *   builds the argument and `install()` arms it through `upgrader_package_options`, which
 *   `run()` applies before `install_package()` reads it.
 * - `install()` never toggles maintenance mode either, so replacing an **active** plugin or theme
 *   would swap files underneath live requests. This brackets the call.
 *
 * @since 0.0.45
 */
final class Asset_Installer {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The `temp_backup` argument that makes core restore this asset if the package fails.
	 *
	 * Pure so it is testable without a filesystem: `WP_Upgrader::move_to_temp_backup_dir()` wants
	 * exactly `slug`, `src` and `dir`, bails on an empty one, and bails on a slug of `.` because
	 * the resulting path ends in a period and `mkdir()` fails. Returning an empty array for those
	 * cases means {@see self::install()} declines to overwrite rather than overwriting unprotected.
	 *
	 * @since  0.0.45
	 * @param  string $type `plugin` or `theme`.
	 * @param  string $slug Asset directory name — a plugin folder or a theme stylesheet.
	 * @param  string $root Containing directory. Defaults to the right root for the type.
	 * @return array{slug:string,src:string,dir:string}|array{} Empty when it cannot be armed.
	 */
	public static function temp_backup_args( string $type, string $slug, string $root = '' ): array {
		$slug = trim( $slug );

		// A slug of '.' or '..' is what a single-file plugin at the plugins root resolves to. Core
		// refuses to back that up, so there would be nothing to restore.
		if ( '' === $slug || '.' === $slug || '..' === $slug ) {
			return array();
		}

		$is_theme = 'theme' === $type;

		if ( '' === $root ) {
			$root = $is_theme
				? ( function_exists( 'get_theme_root' ) ? (string) get_theme_root( $slug ) : '' )
				: ( defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '' );
		}

		if ( '' === $root ) {
			return array();
		}

		return array(
			'slug' => $slug,
			'src'  => $root,
			'dir'  => $is_theme ? 'themes' : 'plugins',
		);
	}

	/**
	 * Read an upgrader outcome, which has four shapes and only one of them means success.
	 *
	 * `install()` returns true, false, null, or a `WP_Error`, and the interesting failures arrive
	 * on the skin rather than in the return value. Pure, and separate from {@see self::install()},
	 * so every branch is reachable in a test without a network fetch.
	 *
	 * @since  0.0.45
	 * @param  mixed    $result   Whatever the upgrader returned.
	 * @param  string[] $errors   Messages from the skin's error bag.
	 * @param  string[] $feedback Messages from the skin's upgrade log.
	 * @return array{ok:bool,message:string}
	 */
	public static function read_outcome( $result, array $errors = array(), array $feedback = array() ): array {
		if ( $result instanceof \WP_Error ) {
			return array(
				'ok'      => false,
				'message' => $result->get_error_message(),
			);
		}

		if ( true === $result ) {
			return array(
				'ok'      => true,
				'message' => '',
			);
		}

		// Trim before discarding: skins emit whitespace-only strings, and `strlen` happily keeps
		// a pair of spaces. Filtering on length alone produced a message of two spaces — a failure
		// that read as "no reason given" while a real reason sat in the next element.
		$clean = static function ( array $messages ): array {
			$trimmed = array_map(
				static function ( $message ): string {
					return trim( (string) $message );
				},
				$messages
			);

			return array_values(
				array_filter(
					$trimmed,
					static function ( string $message ): bool {
						return '' !== $message;
					}
				)
			);
		};

		$errors   = $clean( $errors );
		$feedback = $clean( $feedback );

		if ( array() !== $errors ) {
			$message = implode( ' ', $errors );
		} elseif ( array() !== $feedback ) {
			$message = implode( ' ', $feedback );
		} else {
			$message = __( 'The package did not report success, and reported no reason.', 'acrossai-abilities-manager' );
		}

		return array(
			'ok'      => false,
			'message' => $message,
		);
	}

	/**
	 * The package URL WordPress.org publishes for one exact version.
	 *
	 * Exact match only, deliberately. A caller asking for 6.4 must not silently receive 6.4.1:
	 * the reason to name a version is usually that a specific one is known to work, and a
	 * near-miss defeats the point while looking like a success. Pure, so it executes in tests.
	 *
	 * @since  0.0.45
	 * @param  array<string,mixed> $versions WordPress.org version map, version => package URL.
	 * @param  string              $version  Requested version.
	 * @return string Package URL, or '' when that version is not published.
	 */
	public static function pick_version( array $versions, string $version ): string {
		$version = trim( $version );

		if ( '' === $version || ! isset( $versions[ $version ] ) ) {
			return '';
		}

		$package = $versions[ $version ];

		return is_string( $package ) ? trim( $package ) : '';
	}

	/**
	 * Overwrite an installed asset with a package.
	 *
	 * @since  0.0.45
	 * @param  string $type        `plugin` or `theme`.
	 * @param  string $slug        Asset directory name.
	 * @param  string $package_url Package to apply.
	 * @return array{ok:bool,message:string}|\WP_Error
	 */
	public static function install( string $type, string $slug, string $package_url ) {
		if ( '' === trim( $package_url ) ) {
			return new \WP_Error(
				'no_package_url',
				__( 'No package URL to install.', 'acrossai-abilities-manager' )
			);
		}

		$backup = self::temp_backup_args( $type, $slug );

		if ( array() === $backup ) {
			// Refuse rather than overwrite without a fallback. An overwrite clears the destination
			// first, so proceeding here risks deleting a working asset to apply a package that may
			// not unpack.
			return new \WP_Error(
				'no_restore_point',
				sprintf(
					/* translators: 1: asset type, 2: slug */
					__( 'Cannot establish a restore point for the %1$s "%2$s", so overwriting it was refused. Without one, a package that fails to apply would leave nothing behind.', 'acrossai-abilities-manager' ),
					$type,
					$slug
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		if ( ! class_exists( '\Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = 'theme' === $type ? new \Theme_Upgrader( $skin ) : new \Plugin_Upgrader( $skin );

		// Arm core's restore-on-failure. `run()` applies this filter before `install_package()`
		// reads `hook_extra['temp_backup']`, and the restore branch keys on nothing else — it does
		// not care whether the operation was an install or an update.
		$arm = static function ( $options ) use ( $backup ) {
			if ( is_array( $options ) ) {
				if ( ! isset( $options['hook_extra'] ) || ! is_array( $options['hook_extra'] ) ) {
					$options['hook_extra'] = array();
				}

				$options['hook_extra']['temp_backup'] = $backup;
			}

			return $options;
		};

		add_filter( 'upgrader_package_options', $arm );

		// Replacing the files of an active asset under live requests is what maintenance mode is
		// for, and `install()` does not toggle it the way `upgrade()` does. The `finally` is the
		// point: an exception here must not leave the site showing a maintenance page.
		$upgrader->maintenance_mode( true );

		try {
			$result = $upgrader->install(
				$package_url,
				array( 'overwrite_package' => true )
			);
		} finally {
			$upgrader->maintenance_mode( false );
			remove_filter( 'upgrader_package_options', $arm );
		}

		$skin_errors = $skin->get_errors();

		$outcome = self::read_outcome(
			$result,
			is_wp_error( $skin_errors ) && $skin_errors->has_errors() ? $skin_errors->get_error_messages() : array(),
			(array) $skin->get_upgrade_messages()
		);

		if ( 'theme' === $type ) {
			wp_clean_themes_cache();
		} else {
			wp_clean_plugins_cache();
		}

		return $outcome;
	}
}
