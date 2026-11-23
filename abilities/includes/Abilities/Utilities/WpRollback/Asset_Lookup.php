<?php
/**
 * Feature 136 — resolving a caller's plugin or theme, and what WordPress.org published for it.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\WpRollback
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Plugin_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * One resolver for both asset types, so every ability in the suite agrees about what it was given.
 *
 * @since 0.0.45
 */
final class Asset_Lookup {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Resolve a caller identifier to an installed plugin or theme.
	 *
	 * Plugins go through {@see Plugin_Helpers::resolve_plugin()} — the six-tier matcher, with the
	 * same 8.0 certainty threshold the rest of the suite uses, so a folder name, a display name, a
	 * plugin file or a partial all work and an ambiguous identifier is refused rather than guessed.
	 * That consistency is the whole point of #297.
	 *
	 * @since  0.0.45
	 * @param  string $type       `plugin` or `theme`.
	 * @param  string $identifier Caller input.
	 * @return array{type:string,slug:string,name:string,installed_version:string,plugin_file:string}|\WP_Error
	 */
	public static function resolve( string $type, string $identifier ) {
		$identifier = trim( $identifier );

		if ( '' === $identifier ) {
			return new \WP_Error(
				'missing_identifier',
				'theme' === $type
					? __( 'A theme stylesheet is required.', 'acrossai-abilities-manager' )
					: __( 'A plugin identifier is required.', 'acrossai-abilities-manager' )
			);
		}

		if ( 'theme' === $type ) {
			return self::resolve_theme( $identifier );
		}

		return self::resolve_plugin( $identifier );
	}

	/**
	 * @since  0.0.45
	 * @param  string $identifier Caller input.
	 * @return array<string,string>|\WP_Error
	 */
	private static function resolve_plugin( string $identifier ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$resolved = Plugin_Helpers::resolve_plugin( $identifier );

		if ( null === $resolved['plugin_file'] ) {
			return new \WP_Error(
				'asset_not_found',
				sprintf(
					/* translators: %s: caller identifier */
					__( 'No installed plugin found matching "%s".', 'acrossai-abilities-manager' ),
					$identifier
				)
			);
		}

		if ( $resolved['certainty'] < 8.0 ) {
			return new \WP_Error(
				'ambiguous_identifier',
				sprintf(
					/* translators: 1: caller identifier, 2: best guess */
					__( '"%1$s" is ambiguous — the closest match is "%2$s". Pass the plugin file or the exact folder name.', 'acrossai-abilities-manager' ),
					$identifier,
					(string) $resolved['plugin_name']
				)
			);
		}

		$plugin_file = (string) $resolved['plugin_file'];
		$slug        = dirname( $plugin_file );

		if ( '.' === $slug || '' === $slug ) {
			return new \WP_Error(
				'single_file_plugin',
				sprintf(
					/* translators: %s: plugin file */
					__( '"%s" is a single-file plugin at the plugins-directory root. WordPress.org publishes no versioned package for that shape, so it cannot be re-versioned.', 'acrossai-abilities-manager' ),
					$plugin_file
				)
			);
		}

		$data = Plugin_Helpers::get_plugin_by_slug( $plugin_file );

		return array(
			'type'              => 'plugin',
			'slug'              => $slug,
			'name'              => (string) $resolved['plugin_name'],
			'installed_version' => null !== $data && isset( $data['version'] ) ? (string) $data['version'] : '',
			'plugin_file'       => $plugin_file,
		);
	}

	/**
	 * @since  0.0.45
	 * @param  string $stylesheet Theme directory name.
	 * @return array<string,string>|\WP_Error
	 */
	private static function resolve_theme( string $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return new \WP_Error(
				'asset_not_found',
				sprintf(
					/* translators: %s: stylesheet */
					__( 'No installed theme found with stylesheet "%s".', 'acrossai-abilities-manager' ),
					$stylesheet
				)
			);
		}

		return array(
			'type'              => 'theme',
			'slug'              => $theme->get_stylesheet(),
			'name'              => (string) $theme->get( 'Name' ),
			'installed_version' => (string) $theme->get( 'Version' ),
			'plugin_file'       => '',
		);
	}

	/**
	 * The version map WordPress.org publishes for an asset.
	 *
	 * Returns `not_on_wordpress_org` when the directory does not know the slug, which is the normal
	 * answer for a premium asset — measured against `advanced-custom-fields-pro`, which returns
	 * `plugins_api_failed`. Treated as an answer about the asset rather than an error about us.
	 *
	 * @since  0.0.45
	 * @param  string $type `plugin` or `theme`.
	 * @param  string $slug Asset slug.
	 * @return array<string,mixed>|\WP_Error version => package URL.
	 */
	public static function published_versions( string $type, string $slug ) {
		if ( 'theme' === $type ) {
			// themes_api() lives in theme.php, NOT theme-install.php — unlike plugins_api(), which
			// is in plugin-install.php. Requiring the wrong one left the function undefined and the
			// ability threw instead of answering. Caught by running the theme path; the plugin path
			// had worked all along, which is exactly how an asymmetry like this survives review.
			if ( ! function_exists( 'themes_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/theme.php';
			}

			$response = themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'versions' => true ),
				)
			);
		} else {
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			$response = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'versions' => true ),
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'not_on_wordpress_org',
				sprintf(
					/* translators: 1: asset type, 2: slug */
					__( 'WordPress.org publishes no %1$s with the slug "%2$s", so it has no release history to roll back to. Premium and privately-hosted assets are not in the directory.', 'acrossai-abilities-manager' ),
					$type,
					$slug
				)
			);
		}

		$versions = is_object( $response ) && isset( $response->versions ) ? (array) $response->versions : array();

		if ( array() === $versions ) {
			return new \WP_Error(
				'no_published_versions',
				sprintf(
					/* translators: 1: asset type, 2: slug */
					__( 'WordPress.org knows the %1$s "%2$s" but published no version history for it.', 'acrossai-abilities-manager' ),
					$type,
					$slug
				)
			);
		}

		return $versions;
	}
}
