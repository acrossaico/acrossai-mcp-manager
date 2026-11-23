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

defined( 'ABSPATH' ) || exit;

/**
 * Finds every storage location holding a template with the given slug:
 *   - wp_template CPT row in the database (DB always wins for WordPress)
 *   - .html file in the child theme's /templates directory
 *   - .html file in the parent theme's /templates directory
 *   - .html file in any installed plugin's /templates directory
 *
 * WordPress's runtime priority is DB → child → parent → plugin. The abilities
 * use this list to enforce the scenarios in the spec.
 */
final class Template_Detector {

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
		$post = Template_Db::find_by_slug( $slug, $theme );
		if ( ! $post ) {
			$post = Template_Db::find_by_slug( $slug );
		}
		if ( $post ) {
			$locations[] = array(
				'source'  => 'db',
				'slug'    => (string) $post->post_name,
				'post_id' => (int) $post->ID,
				'theme'   => Template_Db::get_post_theme( $post ),
			);
		}

		// 2. Child theme.
		$child_dir = Template_File::get_child_theme_dir();
		if ( null !== $child_dir ) {
			$file = $child_dir . '/templates/' . $slug . '.html';
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

		// 3. Parent theme (or single theme).
		$parent_dir   = Template_File::get_parent_theme_dir();
		$parent_file  = $parent_dir . '/templates/' . $slug . '.html';
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

		// 4. Plugins with /templates dirs.
		foreach ( Template_File::scan_plugins_with_templates() as $plugin ) {
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
	 * Filters a locations list down to one matching the caller's source
	 * preferences. Returns:
	 *   - WP_Error('not_found') when no location exists (Scenario 10)
	 *   - WP_Error('multiple_locations') when ambiguous (Scenarios 8, 9, 11)
	 *   - the single matching location array otherwise
	 *
	 * @param array<int, array<string, mixed>> $locations
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function select( array $locations, string $source = '', string $theme_type = '', string $plugin_slug = '' ) {
		if ( empty( $locations ) ) {
			return new \WP_Error( 'not_found', __( 'No template with this slug was found in the database, the active theme, or any plugin. Use template-create to add one.', 'acrossai-abilities-manager' ) );
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
			$error = new \WP_Error( 'not_found_at_source', __( 'No template with this slug exists at the requested source.', 'acrossai-abilities-manager' ) );
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
		foreach ( $locations as $loc ) {
			if ( ( $loc['source'] ?? '' ) === 'db' ) {
				return $loc;
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
