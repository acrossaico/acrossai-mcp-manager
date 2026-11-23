<?php
/**
 * Target resolver shared by Create_Zip_Backup and Extract_Zip_Backup (Feature 041).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the (target_type, target) input pair used by the Create_Zip_Backup /
 * Extract_Zip_Backup abilities into an absolute filesystem path that lives inside
 * the WordPress install.
 *
 * Supported target_type values:
 *
 *   - plugin      : $target is a plugin slug or plugin file. Resolved via
 *                   Plugin_Helpers; returns the plugin's containing directory.
 *   - theme       : $target is a stylesheet or theme name. Resolved via
 *                   Theme_Helpers; returns the theme's directory.
 *   - uploads     : $target is ignored. Returns wp_get_upload_dir()['basedir'].
 *   - mu-plugins  : $target is ignored. Returns WPMU_PLUGIN_DIR.
 *   - path        : $target is a path relative to ABSPATH. Returns the
 *                   absolute path after a realpath() boundary check.
 *
 * Every branch protects against path traversal — the returned path must be a
 * child of ABSPATH; anything outside is rejected as a WP_Error.
 */
final class Zip_Target_Resolver {

	public const TYPE_PLUGIN     = 'plugin';
	public const TYPE_THEME      = 'theme';
	public const TYPE_UPLOADS    = 'uploads';
	public const TYPE_MU_PLUGINS = 'mu-plugins';
	public const TYPE_PATH       = 'path';

	/**
	 * All supported target types (used by JSON Schema enums).
	 *
	 * @return string[]
	 */
	public static function supported_types(): array {
		return array(
			self::TYPE_PLUGIN,
			self::TYPE_THEME,
			self::TYPE_UPLOADS,
			self::TYPE_MU_PLUGINS,
			self::TYPE_PATH,
		);
	}

	/**
	 * Resolve a source directory (must exist).
	 *
	 * @return array{abs_path: string, label: string}|\WP_Error
	 */
	public static function resolve_source( string $target_type, string $target ) {
		$resolved = self::resolve( $target_type, $target, false );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( ! is_dir( $resolved['abs_path'] ) ) {
			return new \WP_Error(
				'target_not_found',
				sprintf(
					/* translators: %s: resolved absolute path */
					__( 'The resolved target directory does not exist: %s', 'acrossai-abilities-manager' ),
					$resolved['abs_path']
				)
			);
		}
		return $resolved;
	}

	/**
	 * Resolve a destination directory. Non-existent directories are created
	 * for path/uploads targets (subfolder inside uploads/) and must already
	 * exist for plugin / theme / mu-plugins.
	 *
	 * @return array{abs_path: string, label: string}|\WP_Error
	 */
	public static function resolve_destination( string $target_type, string $target ) {
		$resolved = self::resolve( $target_type, $target, true );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( ! is_dir( $resolved['abs_path'] ) ) {
			if ( ! wp_mkdir_p( $resolved['abs_path'] ) ) {
				return new \WP_Error(
					'destination_create_failed',
					sprintf(
						/* translators: %s: resolved absolute path */
						__( 'Could not create destination directory: %s', 'acrossai-abilities-manager' ),
						$resolved['abs_path']
					)
				);
			}
		}
		return $resolved;
	}

	/**
	 * Core resolution shared by resolve_source() and resolve_destination().
	 *
	 * @param bool $for_destination When true, the boundary check tolerates a
	 *                              missing directory (the caller may create it).
	 *
	 * @return array{abs_path: string, label: string}|\WP_Error
	 */
	private static function resolve( string $target_type, string $target, bool $for_destination ) {
		$target_type = sanitize_key( $target_type );
		$target      = sanitize_text_field( $target );

		if ( ! in_array( $target_type, self::supported_types(), true ) ) {
			return new \WP_Error(
				'invalid_target_type',
				sprintf(
					/* translators: 1: submitted target_type, 2: comma-separated list of supported types */
					__( 'Unsupported target_type "%1$s". Must be one of: %2$s', 'acrossai-abilities-manager' ),
					$target_type,
					implode( ', ', self::supported_types() )
				)
			);
		}

		switch ( $target_type ) {
			case self::TYPE_PLUGIN:
				return self::resolve_plugin_dir( $target );

			case self::TYPE_THEME:
				return self::resolve_theme_dir( $target );

			case self::TYPE_UPLOADS:
				$uploads = wp_upload_dir( null, false );
				if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
					return new \WP_Error(
						'uploads_unavailable',
						__( 'Uploads directory is unavailable.', 'acrossai-abilities-manager' )
					);
				}
				return array(
					'abs_path' => rtrim( (string) $uploads['basedir'], '/' ),
					'label'    => 'uploads',
				);

			case self::TYPE_MU_PLUGINS:
				if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
					return new \WP_Error(
						'mu_plugins_unavailable',
						__( 'WPMU_PLUGIN_DIR is not defined on this install.', 'acrossai-abilities-manager' )
					);
				}
				$mu_dir = rtrim( (string) WPMU_PLUGIN_DIR, '/' );
				if ( $for_destination && ! is_dir( $mu_dir ) ) {
					if ( ! wp_mkdir_p( $mu_dir ) ) {
						return new \WP_Error(
							'mu_plugins_create_failed',
							__( 'Could not create the mu-plugins directory.', 'acrossai-abilities-manager' )
						);
					}
				}
				return array(
					'abs_path' => $mu_dir,
					'label'    => 'mu-plugins',
				);

			case self::TYPE_PATH:
			default:
				return self::resolve_abspath_relative( $target, $for_destination );
		}
	}

	/**
	 * Resolve a plugin slug / file / name to its containing directory.
	 *
	 * @return array{abs_path: string, label: string}|\WP_Error
	 */
	private static function resolve_plugin_dir( string $target ) {
		if ( '' === $target ) {
			return new \WP_Error(
				'invalid_target',
				__( 'A plugin slug is required when target_type is "plugin".', 'acrossai-abilities-manager' )
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = null;

		if ( function_exists( '\get_plugins' ) ) {
			$installed = get_plugins();
			if ( isset( $installed[ $target ] ) ) {
				$plugin_file = $target;
			}
		}

		if ( null === $plugin_file ) {
			$resolved = Plugin_Helpers::resolve_plugin( $target );
			if ( ! empty( $resolved['plugin_file'] ) && $resolved['certainty'] >= 8.0 ) {
				$plugin_file = (string) $resolved['plugin_file'];
			}
		}

		if ( null === $plugin_file ) {
			$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? rtrim( (string) WP_PLUGIN_DIR, '/' ) : rtrim( WP_CONTENT_DIR, '/' ) . '/plugins';
			$candidate   = $plugins_dir . '/' . ltrim( $target, '/' );
			$candidate   = rtrim( $candidate, '/' );
			if ( is_dir( $candidate ) ) {
				return array(
					'abs_path' => $candidate,
					'label'    => 'plugin:' . basename( $candidate ),
				);
			}

			return new \WP_Error(
				'plugin_not_found',
				sprintf(
					/* translators: %s: submitted plugin slug */
					__( 'Could not resolve plugin "%s".', 'acrossai-abilities-manager' ),
					$target
				)
			);
		}

		$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? rtrim( (string) WP_PLUGIN_DIR, '/' ) : rtrim( WP_CONTENT_DIR, '/' ) . '/plugins';

		// A plugin file like "hello.php" (single-file plugin) has no dir of its own — zip the file itself would be surprising.
		// For a plugin file like "hello-dolly/hello.php" the directory is "hello-dolly".
		$slug_dir = strtok( $plugin_file, '/' );
		if ( false === $slug_dir || '' === $slug_dir || strpos( $plugin_file, '/' ) === false ) {
			return new \WP_Error(
				'plugin_single_file',
				sprintf(
					/* translators: %s: plugin file path */
					__( 'Plugin "%s" is a single file with no directory to archive.', 'acrossai-abilities-manager' ),
					$plugin_file
				)
			);
		}

		$abs = $plugins_dir . '/' . $slug_dir;
		if ( ! is_dir( $abs ) ) {
			return new \WP_Error(
				'plugin_dir_missing',
				sprintf(
					/* translators: %s: plugin directory path */
					__( 'Resolved plugin directory does not exist: %s', 'acrossai-abilities-manager' ),
					$abs
				)
			);
		}

		return array(
			'abs_path' => $abs,
			'label'    => 'plugin:' . $slug_dir,
		);
	}

	/**
	 * Resolve a theme stylesheet / name to its directory.
	 *
	 * @return array{abs_path: string, label: string}|\WP_Error
	 */
	private static function resolve_theme_dir( string $target ) {
		if ( '' === $target ) {
			return new \WP_Error(
				'invalid_target',
				__( 'A theme stylesheet is required when target_type is "theme".', 'acrossai-abilities-manager' )
			);
		}

		$stylesheet = null;

		if ( function_exists( '\wp_get_theme' ) ) {
			$theme = wp_get_theme( $target );
			if ( $theme && $theme->exists() ) {
				$stylesheet = (string) $theme->get_stylesheet();
			}
		}

		if ( null === $stylesheet ) {
			$resolved = Theme_Helpers::resolve_theme( $target );
			if ( ! empty( $resolved['stylesheet'] ) && $resolved['certainty'] >= 8.0 ) {
				$stylesheet = (string) $resolved['stylesheet'];
			}
		}

		if ( null === $stylesheet ) {
			return new \WP_Error(
				'theme_not_found',
				sprintf(
					/* translators: %s: submitted theme identifier */
					__( 'Could not resolve theme "%s".', 'acrossai-abilities-manager' ),
					$target
				)
			);
		}

		$themes_root = get_theme_root( $stylesheet );
		if ( ! is_string( $themes_root ) || '' === $themes_root ) {
			$themes_root = rtrim( WP_CONTENT_DIR, '/' ) . '/themes';
		}
		$abs = rtrim( $themes_root, '/' ) . '/' . $stylesheet;

		if ( ! is_dir( $abs ) ) {
			return new \WP_Error(
				'theme_dir_missing',
				sprintf(
					/* translators: %s: theme directory */
					__( 'Resolved theme directory does not exist: %s', 'acrossai-abilities-manager' ),
					$abs
				)
			);
		}

		return array(
			'abs_path' => $abs,
			'label'    => 'theme:' . $stylesheet,
		);
	}

	/**
	 * Resolve an ABSPATH-relative path with a realpath boundary check.
	 *
	 * @return array{abs_path: string, label: string}|\WP_Error
	 */
	private static function resolve_abspath_relative( string $rel_path, bool $for_destination ) {
		if ( '' === $rel_path ) {
			return new \WP_Error(
				'invalid_target',
				__( 'A path is required when target_type is "path".', 'acrossai-abilities-manager' )
			);
		}
		if ( false !== strpos( $rel_path, "\0" ) ) {
			return new \WP_Error(
				'invalid_path',
				__( 'Path contains disallowed characters.', 'acrossai-abilities-manager' )
			);
		}

		// Feature 135: resolve against every root WordPress uses. A zip source or destination under
		// a WP_CONTENT_DIR that lives outside ABSPATH is the common case on managed hosts, and the
		// old ABSPATH-only bound made backing up the live content directory impossible.
		$resolved = Fs_Roots::resolve( $rel_path, ! $for_destination );

		if ( is_wp_error( $resolved ) ) {
			return new \WP_Error( 'path_out_of_bounds', $resolved->get_error_message() );
		}

		if ( ! $for_destination ) {
			return array(
				'abs_path' => $resolved['path'],
				'label'    => 'path:' . $rel_path,
			);
		}

		// For a destination the leaf may not exist yet; resolve() has already contained its parent.
		return array(
			'abs_path' => $resolved['path'],
			'label'    => 'path:' . $rel_path,
		);
	}
}
