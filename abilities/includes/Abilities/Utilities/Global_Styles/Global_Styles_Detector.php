<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Global_Styles
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every storage location holding Global Styles for a theme:
 *   - wp_global_styles CPT row (always wins for WordPress)
 *   - child theme/theme.json
 *   - parent theme/theme.json
 *   - any installed plugin's /theme.json
 *
 * Unlike Template/Pattern detectors, Global Styles are theme-scoped rather than
 * slug-scoped — each theme has at most one DB record and one theme.json per
 * container.
 */
final class Global_Styles_Detector {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function locate( string $theme_hint = '' ): array {
		$theme = '' !== $theme_hint ? sanitize_key( $theme_hint ) : (string) get_stylesheet();

		$locations = array();

		// 1. Database (always wins).
		$post = Global_Styles_Db::find_by_theme( $theme );
		if ( $post ) {
			$location = array(
				'source'               => 'db',
				'theme'                => $theme,
				'post_id'              => (int) $post->ID,
				'customized_sections'  => Global_Styles_Db::get_customized_sections( $post ),
				// The DB copy outranks every file, but only while WordPress is willing to use it.
				// Callers that report what the site is serving need both facts, not just the rank.
				'applied_by_wordpress' => Global_Styles_Db::is_applied_by_wordpress( $post ),
			);

			$record_warnings = Global_Styles_Db::record_warnings( $post );
			if ( ! empty( $record_warnings ) ) {
				$location['warnings'] = $record_warnings;
			}

			$locations[] = $location;
		}

		// 2. Child theme.
		$child_dir = Global_Styles_File::get_child_theme_dir();
		if ( null !== $child_dir ) {
			$file = Global_Styles_File::theme_json_path( $child_dir );
			if ( is_file( $file ) ) {
				$locations[] = array(
					'source'     => 'theme',
					'theme_type' => 'child',
					'theme'      => basename( $child_dir ),
					'path'       => $file,
					'writable'   => is_writable( $file ),
				);
			}
		}

		// 3. Parent (or single) theme.
		$parent_dir   = Global_Styles_File::get_parent_theme_dir();
		$parent_file  = Global_Styles_File::theme_json_path( $parent_dir );
		$is_child_set = null !== $child_dir;
		if ( is_file( $parent_file ) ) {
			$locations[] = array(
				'source'     => 'theme',
				'theme_type' => $is_child_set ? 'parent' : 'theme',
				'theme'      => basename( $parent_dir ),
				'path'       => $parent_file,
				'writable'   => is_writable( $parent_file ),
			);
		}

		// 4. Plugins with theme.json.
		foreach ( Global_Styles_File::scan_plugins_with_theme_json() as $plugin ) {
			$locations[] = array(
				'source'        => 'plugin',
				'plugin'        => $plugin['slug'],
				'plugin_active' => (bool) $plugin['active'],
				'path'          => $plugin['path'],
				'writable'      => is_writable( $plugin['path'] ),
			);
		}

		return $locations;
	}

	/**
	 * Filters a locations list down to one matching the caller's source
	 * preferences. Mirrors the Template detector's contract.
	 *
	 * @param array<int, array<string, mixed>> $locations
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function select( array $locations, string $source = '', string $theme_type = '', string $plugin_slug = '' ) {
		if ( empty( $locations ) ) {
			return new \WP_Error( 'not_found', __( 'No Global Styles record was found for this theme in the database, theme files, or any plugin. Use global-styles-create to add one.', 'acrossai-abilities-manager' ) );
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
			$error = new \WP_Error( 'not_found_at_source', __( 'No Global Styles record exists at the requested source.', 'acrossai-abilities-manager' ) );
			$error->add_data( array( 'locations' => $locations ) );
			return $error;
		}

		if ( count( $candidates ) > 1 ) {
			$error = new \WP_Error(
				'multiple_locations',
				__( 'Global Styles exist in more than one location. Specify "source" (and "theme_type" or "plugin_slug") to pick one. WordPress always uses the DB version when one exists.', 'acrossai-abilities-manager' )
			);
			$error->add_data( array( 'locations' => $candidates ) );
			return $error;
		}

		return $candidates[0];
	}

	/**
	 * Picks the WP-canonical location: DB → child → parent → plugin.
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
