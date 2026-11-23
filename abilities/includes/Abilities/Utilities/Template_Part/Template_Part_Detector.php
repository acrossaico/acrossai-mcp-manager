<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Template_Part
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Template_Part;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every storage location holding a template part with the given slug:
 *   - wp_template_part CPT row in the database (DB always wins for WordPress)
 *   - .html file in the child theme's /parts directory
 *   - .html file in the parent theme's /parts directory
 *   - .html file in any installed plugin's /parts directory
 *
 * Returns a flat list of location descriptors. WordPress's runtime priority is
 * DB → child → parent → plugin; the abilities use this list to enforce the
 * scenarios in the spec (act on the unambiguous location, surface a
 * multi-location warning, refuse to act when missing).
 */
final class Template_Part_Detector {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function locate( string $slug, string $theme_hint = '' ): array {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return array();
		}

		$theme = '' !== $theme_hint ? sanitize_key( $theme_hint ) : (string) get_stylesheet();

		$locations = array();

		// 1. Database — prefer theme-scoped lookup, fall back to any theme.
		$post = Template_Part_Db::find_by_slug( $slug, $theme );
		if ( ! $post ) {
			$post = Template_Part_Db::find_by_slug( $slug );
		}
		if ( $post ) {
			$locations[] = array(
				'source'  => 'db',
				'slug'    => (string) $post->post_name,
				'post_id' => (int) $post->ID,
				'theme'   => Template_Part_Db::get_post_theme( $post ),
				'area'    => Template_Part_Db::get_post_area( $post ),
			);
		}

		// 2. Child theme.
		$child_dir = Template_Part_File::get_child_theme_dir();
		if ( null !== $child_dir ) {
			$file = $child_dir . '/parts/' . $slug . '.html';
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

		// 3. Parent theme (or single theme — labelled "theme" when no child is active).
		$parent_dir   = Template_Part_File::get_parent_theme_dir();
		$parent_file  = $parent_dir . '/parts/' . $slug . '.html';
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

		// 4. Plugins with /parts dirs.
		foreach ( Template_Part_File::scan_plugins_with_parts() as $plugin ) {
			$file = $plugin['path'] . '/' . $slug . '.html';
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
	 *   - WP_Error('not_found',  …) when no location exists (scenario 10)
	 *   - WP_Error('multiple_locations', …) when ambiguous (scenarios 8, 9, 11)
	 *     — passes the candidate list in error_data so callers can surface
	 *     action buttons
	 *   - the single matching location array otherwise
	 *
	 * Selectors:
	 *   $source       'db' | 'theme' | 'plugin' | ''   (empty = no preference)
	 *   $theme_type   'child' | 'parent' | 'theme' | ''
	 *   $plugin_slug  plugin folder name
	 *
	 * @param array<int, array<string, mixed>> $locations
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function select( array $locations, string $source = '', string $theme_type = '', string $plugin_slug = '' ) {
		if ( empty( $locations ) ) {
			return new \WP_Error( 'not_found', __( 'No template part with this slug was found in the database, the active theme, or any plugin. Use template-part-create to add one.', 'acrossai-abilities-manager' ) );
		}

		$candidates = $locations;

		if ( '' !== $source ) {
			$normalized = ( 'child_theme' === $source ) ? 'theme' : $source;
			$want_child = ( 'child_theme' === $source );
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( $loc ) use ( $normalized, $want_child ): bool {
						if ( ( $loc['source'] ?? '' ) !== $normalized ) {
							return false;
						}
						if ( $want_child ) {
							return ( $loc['theme_type'] ?? '' ) === 'child';
						}
						return true;
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
			$error = new \WP_Error( 'not_found_at_source', __( 'No template part with this slug exists at the requested source.', 'acrossai-abilities-manager' ) );
			$error->add_data( array( 'locations' => $locations ) );
			return $error;
		}

		if ( count( $candidates ) > 1 ) {
			$error = new \WP_Error(
				'multiple_locations',
				__( 'This slug exists in more than one location. Specify "source" (and "theme_type" or "plugin_slug") to pick one. WordPress always uses the DB version when one exists.', 'acrossai-abilities-manager' )
			);
			$error->add_data( array( 'locations' => $candidates ) );
			return $error;
		}

		return $candidates[0];
	}

	/**
	 * Picks the WordPress-canonical location from a list — the one WP will
	 * actually use at runtime. Honours DB → child → parent → plugin priority.
	 *
	 * @param array<int, array<string, mixed>> $locations
	 * @return array<string, mixed>|null
	 */
	public static function effective( array $locations ): ?array {
		foreach ( array( 'db' ) as $src ) {
			foreach ( $locations as $loc ) {
				if ( ( $loc['source'] ?? '' ) === $src ) {
					return $loc;
				}
			}
		}
		foreach ( $locations as $loc ) {
			if ( ( $loc['source'] ?? '' ) === 'theme' && ( $loc['theme_type'] ?? '' ) === 'child' ) {
				return $loc;
			}
		}
		foreach ( $locations as $loc ) {
			if ( ( $loc['source'] ?? '' ) === 'theme' ) {
				return $loc;
			}
		}
		foreach ( $locations as $loc ) {
			if ( ( $loc['source'] ?? '' ) === 'plugin' ) {
				return $loc;
			}
		}
		return null;
	}
}
