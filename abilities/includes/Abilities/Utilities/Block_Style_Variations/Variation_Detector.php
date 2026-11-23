<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Block_Style_Variations
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Style_Variations;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every storage location holding a Block Style Variation for a given
 * slug:
 *   - wp_global_styles CPT row (variation, not the main record)
 *   - <slug>.json in the child theme's /styles
 *   - <slug>.json in the parent theme's /styles
 *   - <slug>.json in any installed plugin's /styles
 *
 * WordPress's runtime priority is DB → child → parent → plugin.
 */
final class Variation_Detector {

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

		// 1. Database.
		$post = Variation_Db::find_by_slug( $slug, $theme );
		if ( $post ) {
			$locations[] = array(
				'source'              => 'db',
				'slug'                => (string) $post->post_name,
				'post_id'             => (int) $post->ID,
				'theme'               => Variation_Db::get_post_theme( $post ),
				'is_active_variation' => Variation_Db::is_active_variation( $post ),
				'customized_sections' => Variation_Db::get_customized_sections( $post ),
			);
		}

		// 2. Child theme.
		$child_dir = Variation_File::get_child_theme_dir();
		if ( null !== $child_dir ) {
			$file = $child_dir . '/styles/' . $slug . '.json';
			if ( is_file( $file ) ) {
				$locations[] = array(
					'source'     => 'theme',
					'theme_type' => 'child',
					'theme'      => basename( $child_dir ),
					'slug'       => $slug,
					'path'       => $file,
					'writable'   => is_writable( $file ),
				);
			}
		}

		// 3. Parent (or single) theme.
		$parent_dir   = Variation_File::get_parent_theme_dir();
		$parent_file  = $parent_dir . '/styles/' . $slug . '.json';
		$is_child_set = null !== $child_dir;
		if ( is_file( $parent_file ) ) {
			$locations[] = array(
				'source'     => 'theme',
				'theme_type' => $is_child_set ? 'parent' : 'theme',
				'theme'      => basename( $parent_dir ),
				'slug'       => $slug,
				'path'       => $parent_file,
				'writable'   => is_writable( $parent_file ),
			);
		}

		// 4. Plugins with /styles dirs.
		foreach ( Variation_File::scan_plugins_with_styles() as $plugin ) {
			$file = $plugin['path'] . '/' . $slug . '.json';
			if ( is_file( $file ) ) {
				$locations[] = array(
					'source'        => 'plugin',
					'plugin'        => $plugin['slug'],
					'plugin_active' => (bool) $plugin['active'],
					'slug'          => $slug,
					'path'          => $file,
					'writable'      => is_writable( $file ),
				);
			}
		}

		return $locations;
	}

	/**
	 * @param array<int, array<string, mixed>> $locations
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function select( array $locations, string $source = '', string $theme_type = '', string $plugin_slug = '' ) {
		if ( empty( $locations ) ) {
			return new \WP_Error( 'not_found', __( 'No Block Style Variation with this slug was found in the database, the active theme, or any plugin. Use block-style-variations-create to add one.', 'acrossai-abilities-manager' ) );
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
			$error = new \WP_Error( 'not_found_at_source', __( 'No Block Style Variation with this slug exists at the requested source.', 'acrossai-abilities-manager' ) );
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
