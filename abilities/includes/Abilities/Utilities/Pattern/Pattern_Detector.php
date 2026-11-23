<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Pattern
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every storage location holding a pattern with the given slug:
 *   - wp_block CPT row in the database
 *   - PHP file in the child theme's /patterns
 *   - PHP file in the parent theme's /patterns
 *   - PHP file in any installed plugin's /patterns (active or not)
 *
 * Returns a flat list of location descriptors. The Update/Delete abilities
 * use this to enforce the decision tree in the spec: act on the single
 * location when unambiguous, error when the caller hasn't picked one.
 */
final class Pattern_Detector {

	/**
	 * @return array<int, array{source:string, theme_type?:string, theme?:string, plugin?:string, plugin_active?:bool, path?:string, post_id?:int, slug:string, writable?:bool}>
	 */
	public static function locate( string $slug ): array {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return array();
		}

		$locations = array();

		// 1. Database
		$post = Pattern_Db::find_by_slug( $slug );
		if ( $post ) {
			$locations[] = array(
				'source'  => 'db',
				'slug'    => (string) $post->post_name,
				'post_id' => (int) $post->ID,
			);
		}

		// 2. Child theme (if present)
		$child_dir = Pattern_Helper::get_child_theme_dir();
		if ( null !== $child_dir ) {
			$file = $child_dir . '/patterns/' . $slug . '.php';
			if ( is_file( $file ) ) {
				$locations[] = array(
					'source'     => 'theme',
					'theme_type' => 'child',
					'theme'      => basename( $child_dir ),
					'path'       => $file,
					'slug'       => $slug,
					'writable'   => is_writable( $file ),
				);
			}
		}

		// 3. Parent theme (or single theme — labelled "theme")
		$parent_dir   = Pattern_Helper::get_parent_theme_dir();
		$parent_file  = $parent_dir . '/patterns/' . $slug . '.php';
		$is_child_set = null !== $child_dir;
		if ( is_file( $parent_file ) ) {
			$locations[] = array(
				'source'     => 'theme',
				'theme_type' => $is_child_set ? 'parent' : 'theme',
				'theme'      => basename( $parent_dir ),
				'path'       => $parent_file,
				'slug'       => $slug,
				'writable'   => is_writable( $parent_file ),
			);
		}

		// 4. Plugins with /patterns dirs
		foreach ( Pattern_Helper::scan_plugins_with_patterns() as $plugin ) {
			$file = $plugin['path'] . '/' . $slug . '.php';
			if ( is_file( $file ) ) {
				$locations[] = array(
					'source'        => 'plugin',
					'plugin'        => $plugin['slug'],
					'plugin_active' => (bool) $plugin['active'],
					'path'          => $file,
					'slug'          => $slug,
					'writable'      => is_writable( $file ),
				);
			}
		}

		return $locations;
	}

	/**
	 * Filters a locations list down to one that matches the caller's source
	 * preferences. Returns:
	 *   - WP_Error('not_found',  …) when no location exists
	 *   - WP_Error('multiple_locations', …) when ambiguous (passes locations[] in error_data)
	 *   - the single matching location array otherwise
	 *
	 * Selectors:
	 *   $source       'db' | 'theme' | 'plugin' | ''   (empty = no preference)
	 *   $theme_type   'child' | 'parent' | ''           (only relevant when $source=theme)
	 *   $plugin_slug  plugin folder name                (only relevant when $source=plugin)
	 *
	 * @param array<int, array<string, mixed>> $locations
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function select( array $locations, string $source = '', string $theme_type = '', string $plugin_slug = '' ) {
		if ( empty( $locations ) ) {
			return new \WP_Error( 'not_found', __( 'No pattern with this slug was found in the database, the active theme, or any plugin. Use block-pattern-create to add one.', 'acrossai-abilities-manager' ) );
		}

		$candidates = $locations;

		if ( '' !== $source ) {
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( $loc ) use ( $source ): bool {
						return ( $loc['source'] ?? '' ) === $source;
					}
				)
			);
		}

		if ( 'theme' === $source && '' !== $theme_type ) {
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( $loc ) use ( $theme_type ): bool {
						return ( $loc['theme_type'] ?? '' ) === $theme_type;
					}
				)
			);
		}

		if ( 'plugin' === $source && '' !== $plugin_slug ) {
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( $loc ) use ( $plugin_slug ): bool {
						return ( $loc['plugin'] ?? '' ) === $plugin_slug;
					}
				)
			);
		}

		if ( empty( $candidates ) ) {
			$error = new \WP_Error( 'not_found_at_source', __( 'No pattern with this slug exists at the requested source.', 'acrossai-abilities-manager' ) );
			$error->add_data( array( 'locations' => $locations ) );
			return $error;
		}

		if ( count( $candidates ) > 1 ) {
			$error = new \WP_Error(
				'multiple_locations',
				__( 'This slug exists in more than one location. Specify "source" (and "theme_type" or "plugin_slug") to pick one.', 'acrossai-abilities-manager' )
			);
			$error->add_data( array( 'locations' => $candidates ) );
			return $error;
		}

		return $candidates[0];
	}
}
