<?php
/**
 * Resolve a hooked callback back to the plugin that registered it (Feature 101).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot;

defined( 'ABSPATH' ) || exit;

/**
 * Names the plugin behind a filter callback.
 *
 * "A security plugin is restricting REST access" leaves the operator to find
 * out which, across thirty active plugins, by deactivating them one at a
 * time. Reflection on the callback gives its real file path, and the first
 * path segment under the plugins directory gives the plugin — so the report
 * can say *Wordfence* instead of *something*.
 *
 * Same approach as DiscoveryConflictGuard::conflicting_plugin_name(), which
 * resolves a class rather than a callback. Extracted here because this is its
 * second use (Constitution §VI) and because a callback may be a closure, a
 * function name, or an object method, none of which that version handles.
 */
final class PluginNamer {

	/**
	 * Every plugin with a callback on the given hook, as display names.
	 *
	 * Callbacks belonging to WordPress core, a theme, or this plugin are
	 * excluded: naming ourselves helps nobody, and naming core is actively
	 * misleading since core's own callbacks on these hooks are the default
	 * behaviour rather than an override.
	 *
	 * @param string $hook Filter or action name.
	 * @return array<int, string> Display names, unique, in registration order.
	 */
	public static function plugins_hooked_to( string $hook ): array {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
			return array();
		}

		$names = array();

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$name = self::name_for_callback( $registered['function'] );

				if ( '' !== $name ) {
					$names[ $name ] = true;
				}
			}
		}

		return array_keys( $names );
	}

	/**
	 * Display name of the plugin that owns a callback, or ''.
	 *
	 * @param mixed $callback Anything WordPress accepts as a callback.
	 * @return string
	 */
	private static function name_for_callback( $callback ): string {
		$file = self::file_for_callback( $callback );

		if ( '' === $file ) {
			return '';
		}

		return self::plugin_name_for_file( $file );
	}

	/**
	 * The file a callback was defined in.
	 *
	 * @param mixed $callback Anything WordPress accepts as a callback.
	 * @return string Absolute path, or ''.
	 */
	private static function file_for_callback( $callback ): string {
		try {
			if ( $callback instanceof \Closure ) {
				return (string) ( new \ReflectionFunction( $callback ) )->getFileName();
			}

			if ( is_string( $callback ) && function_exists( $callback ) ) {
				return (string) ( new \ReflectionFunction( $callback ) )->getFileName();
			}

			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				return (string) ( new \ReflectionMethod( $class, $method ) )->getFileName();
			}

			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				return (string) ( new \ReflectionMethod( $callback[0], (string) $callback[1] ) )->getFileName();
			}

			if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				return (string) ( new \ReflectionMethod( $callback, '__invoke' ) )->getFileName();
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Map a file inside the plugins directory to that plugin's display name.
	 *
	 * @param string $file Absolute path.
	 * @return string Display name, the directory slug as a fallback, or ''
	 *                when the file is not a plugin's or is this plugin's own.
	 */
	private static function plugin_name_for_file( string $file ): string {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return '';
		}

		$normalised = wp_normalize_path( $file );
		$plugin_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		if ( 0 !== strpos( $normalised, $plugin_dir ) ) {
			return '';
		}

		$relative = substr( $normalised, strlen( $plugin_dir ) );
		$slug     = strtok( $relative, '/' );

		// A file directly in the plugins directory (a single-file plugin) has
		// no directory segment, so strtok returns the filename, which is a
		// usable slug. Only an empty remainder fails, and that cannot happen
		// for a path that passed the prefix test above — but strtok's false
		// return is the documented failure mode, so it is handled.
		if ( false === $slug ) {
			return '';
		}

		// Pointing the operator at ourselves is noise: this plugin's own
		// callbacks on these hooks are what is doing the asking.
		if ( defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_PATH' )
			&& 0 === strpos( $normalised, trailingslashit( wp_normalize_path( \ACROSSAI_MCP_MANAGER_PLUGIN_PATH ) ) ) ) {
			return '';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			if ( 0 === strpos( $plugin_file, $slug . '/' ) && ! empty( $plugin_data['Name'] ) ) {
				return (string) $plugin_data['Name'];
			}
		}

		return $slug;
	}
}
