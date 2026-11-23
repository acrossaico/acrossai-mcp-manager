<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Template
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Template;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * File-based block-template utilities — resolves theme/plugin /templates
 * directories, sanitises filenames, and guards against path traversal.
 *
 * Templates live in <container>/templates/<slug>.html.
 * Spec: https://developer.wordpress.org/themes/templates/
 */
final class Template_File {

	// -------------------------------------------------------------------------
	// Theme directory resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolves and validates a theme directory.
	 *
	 * @return string|\WP_Error
	 */
	public static function resolve_theme_dir( string $slug ) {
		$themes_dir = rtrim( get_theme_root(), '/' );
		$theme_dir  = '' !== $slug
			? realpath( $themes_dir . '/' . $slug )
			: realpath( get_stylesheet_directory() );

		if ( false === $theme_dir || 0 !== strpos( $theme_dir, $themes_dir ) || ! is_dir( $theme_dir ) ) {
			return new \WP_Error( 'theme_not_found', __( 'Theme directory not found.', 'acrossai-abilities-manager' ) );
		}

		return $theme_dir;
	}

	/**
	 * Get child theme dir.
	 *
	 * @return ?string
	 */
	public static function get_child_theme_dir(): ?string {
		if ( get_template() === get_stylesheet() ) {
			return null;
		}
		$path = realpath( get_stylesheet_directory() );
		return $path ? $path : null;
	}

	/**
	 * Get parent theme dir.
	 *
	 * @return string
	 */
	public static function get_parent_theme_dir(): string {
		return rtrim( get_template_directory(), '/' );
	}

	// -------------------------------------------------------------------------
	// Plugin directory resolution
	// -------------------------------------------------------------------------

	/**
	 * @return array{path: string, active: bool, plugin_file: string}|\WP_Error
	 */
	public static function resolve_plugin_dir( string $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_plugin_slug', __( 'Plugin slug is required.', 'acrossai-abilities-manager' ) );
		}

		$plugins_dir = rtrim( WP_PLUGIN_DIR, '/' );
		$plugin_dir  = realpath( $plugins_dir . '/' . $slug );

		if ( false === $plugin_dir || 0 !== strpos( $plugin_dir, $plugins_dir ) || ! is_dir( $plugin_dir ) ) {
			return new \WP_Error( 'plugin_not_found', __( 'Plugin directory not found.', 'acrossai-abilities-manager' ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_file = '';
		$active      = false;
		foreach ( array_keys( get_plugins() ) as $rel ) {
			if ( 0 === strpos( $rel, $slug . '/' ) ) {
				$plugin_file = $rel;
				$active      = is_plugin_active( $rel );
				break;
			}
		}

		return array(
			'path'        => $plugin_dir,
			'active'      => $active,
			'plugin_file' => $plugin_file,
		);
	}

	/**
	 * Scans installed plugins for ones containing a /templates directory.
	 *
	 * @return array<int, array{slug:string, path:string, active:bool}>
	 */
	public static function scan_plugins_with_templates(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$found = array();
		$seen  = array();
		foreach ( array_keys( get_plugins() ) as $rel ) {
			$parts = explode( '/', $rel );
			$slug  = $parts[0] ?? '';
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			$templates_dir = WP_PLUGIN_DIR . '/' . $slug . '/templates';
			if ( ! is_dir( $templates_dir ) ) {
				continue;
			}
			$found[] = array(
				'slug'   => $slug,
				'path'   => $templates_dir,
				'active' => is_plugin_active( $rel ),
			);
		}
		return $found;
	}

	// -------------------------------------------------------------------------
	// Template file path within /templates
	// -------------------------------------------------------------------------

	/**
	 * Templates dir.
	 *
	 * @param string $container_dir
	 * @return string
	 */
	public static function templates_dir( string $container_dir ): string {
		return rtrim( $container_dir, '/' ) . '/templates';
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function resolve_template_path( string $container_dir, string $filename_or_slug ) {
		$filename = self::sanitize_filename( $filename_or_slug );
		if ( '' === $filename ) {
			return new \WP_Error( 'invalid_filename', __( 'Invalid template filename.', 'acrossai-abilities-manager' ) );
		}

		$templates_dir = self::templates_dir( $container_dir );
		$abs_path      = $templates_dir . '/' . $filename;

		$resolved_dir = realpath( $templates_dir );
		if ( false !== $resolved_dir ) {
			$candidate = realpath( $abs_path );
			if ( false !== $candidate && 0 !== strpos( $candidate, $resolved_dir ) ) {
				return new \WP_Error( 'path_escape', __( 'Template path escapes the /templates directory.', 'acrossai-abilities-manager' ) );
			}
		}

		return $abs_path;
	}

	/**
	 * Sanitize filename.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function sanitize_filename( string $value ): string {
		$value = basename( $value );
		if ( ! preg_match( '/\.html$/i', $value ) ) {
			$value .= '.html';
		}
		$base = preg_replace( '/\.html?$/i', '', $value );
		$base = sanitize_title( $base );
		if ( '' === $base ) {
			return '';
		}
		return $base . '.html';
	}

	/**
	 * Is valid bare slug.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function is_valid_bare_slug( string $slug ): bool {
		return (bool) preg_match( '#^[a-z0-9_-]+$#', $slug );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function ensure_templates_dir( string $container_dir ) {
		$guard = File_Mods_Guard::check();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$templates_dir = self::templates_dir( $container_dir );
		if ( ! is_dir( $templates_dir ) ) {
			if ( ! wp_mkdir_p( $templates_dir ) ) {
				return new \WP_Error( 'mkdir_failed', __( 'Could not create /templates directory.', 'acrossai-abilities-manager' ) );
			}
		}
		if ( ! is_writable( $templates_dir ) ) {
			return new \WP_Error( 'dir_not_writable', __( 'The /templates directory is not writable.', 'acrossai-abilities-manager' ) );
		}
		return $templates_dir;
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function read_file( string $abs_path ) {
		if ( ! is_file( $abs_path ) ) {
			return new \WP_Error( 'file_not_found', __( 'Template file not found.', 'acrossai-abilities-manager' ) );
		}
		$contents = file_get_contents( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return new \WP_Error( 'read_failed', __( 'Could not read template file.', 'acrossai-abilities-manager' ) );
		}
		return $contents;
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function write_file( string $abs_path, string $content ) {
		$guard = File_Mods_Guard::check();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$dir = dirname( $abs_path );
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new \WP_Error( 'mkdir_failed', __( 'Could not create directory for template.', 'acrossai-abilities-manager' ) );
			}
		}
		if ( file_exists( $abs_path ) && ! is_writable( $abs_path ) ) {
			return new \WP_Error(
				'file_not_writable',
				/* translators: %s: absolute file path */
				sprintf( __( 'Template file is read-only: %s. Check file permissions or save to the database instead.', 'acrossai-abilities-manager' ), $abs_path )
			);
		}
		if ( ! file_exists( $abs_path ) && ! is_writable( $dir ) ) {
			return new \WP_Error(
				'dir_not_writable',
				/* translators: %s: directory path */
				sprintf( __( 'Templates directory is not writable: %s.', 'acrossai-abilities-manager' ), $dir )
			);
		}

		$bytes = file_put_contents( $abs_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			return new \WP_Error( 'write_failed', __( 'Could not write template file.', 'acrossai-abilities-manager' ) );
		}
		return (int) $bytes;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function delete_file( string $abs_path ) {
		$guard = File_Mods_Guard::check();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! file_exists( $abs_path ) ) {
			return new \WP_Error( 'file_not_found', __( 'Template file not found.', 'acrossai-abilities-manager' ) );
		}
		if ( ! is_writable( $abs_path ) ) {
			return new \WP_Error(
				'file_not_writable',
				/* translators: %s: absolute file path */
				sprintf( __( 'Cannot delete template file (permission denied): %s.', 'acrossai-abilities-manager' ), $abs_path )
			);
		}
		if ( ! @unlink( $abs_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'unlink_failed', __( 'Could not delete template file.', 'acrossai-abilities-manager' ) );
		}
		return true;
	}
}
