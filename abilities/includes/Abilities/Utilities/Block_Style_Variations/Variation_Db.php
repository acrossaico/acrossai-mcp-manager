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

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Database storage for Block Style Variations. Variations live in the same
 * wp_global_styles CPT as the main Global Styles record, but are
 * differentiated by post_name: the main record has the form
 * "wp-global-styles-{theme}", a variation has its bare slug ("dark",
 * "light", etc.).
 *
 * Each variation is scoped to a theme via wp_theme — multiple variations per
 * theme is the whole point of this CPT use.
 *
 * Validation and JSON encoding piggyback on Global_Styles_Db so the same
 * structural checks, JSON parser, deep-merge and path helpers cover both.
 */
final class Variation_Db {

	public const POST_TYPE = 'wp_global_styles';
	public const THEME_TAX = 'wp_theme';

	/**
	 * Sections exposed by the variation abilities — same as Global_Styles
	 * minus customCss (per the spec).
	 */
	public const SECTIONS = array( 'colors', 'typography', 'spacing', 'layout', 'blockStyles' );

	/**
	 * Map each section to the theme.json paths it owns. Mirrors
	 * Global_Styles_Db::SECTION_PATHS for the 5 supported sections.
	 *
	 * @var array<string, array<int, array<int, string>>>
	 */
	public const SECTION_PATHS = array(
		'colors'      => array( array( 'settings', 'color' ), array( 'styles', 'color' ) ),
		'typography'  => array( array( 'settings', 'typography' ), array( 'styles', 'typography' ) ),
		'spacing'     => array( array( 'settings', 'spacing' ), array( 'styles', 'spacing' ) ),
		'layout'      => array( array( 'settings', 'layout' ) ),
		'blockStyles' => array( array( 'settings', 'blocks' ), array( 'styles', 'blocks' ) ),
	);

	/**
	 * Valid sections.
	 *
	 * @return array
	 */
	public static function valid_sections(): array {
		return self::SECTIONS;
	}

	/**
	 * Valid section.
	 *
	 * @param string $section
	 * @return bool
	 */
	public static function valid_section( string $section ): bool {
		return in_array( self::normalize_section( $section ), self::SECTIONS, true );
	}

	/**
	 * Normalize section.
	 *
	 * @param string $section
	 * @return string
	 */
	public static function normalize_section( string $section ): string {
		$section = strtolower( trim( $section ) );
		$section = str_replace( array( '_', '-', ' ' ), '', $section );
		$map     = array(
			'colors'      => 'colors',
			'typography'  => 'typography',
			'spacing'     => 'spacing',
			'layout'      => 'layout',
			'blockstyles' => 'blockStyles',
		);
		return $map[ $section ] ?? $section;
	}

	/**
	 * Distinguishes the "main" wp_global_styles row (one per theme) from
	 * a variation row. The main row's post_name follows the
	 * "wp-global-styles-{theme}" pattern set by WordPress.
	 */
	public static function is_main_global_styles( \WP_Post $post ): bool {
		return 0 === strpos( (string) $post->post_name, 'wp-global-styles-' );
	}

	// -------------------------------------------------------------------------
	// Lookups
	// -------------------------------------------------------------------------

	/**
	 * Finds a variation post by its bare slug + theme. Returns null when the
	 * matching row is the main global styles record, not a variation.
	 */
	public static function find_by_slug( string $slug, string $theme = '' ): ?\WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$theme = '' !== $theme ? sanitize_key( $theme ) : (string) get_stylesheet();

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'name'           => $slug,
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => self::THEME_TAX,
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			if ( ! self::is_main_global_styles( $post ) ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Lists every variation row, optionally scoped to a theme. The "main"
	 * Global Styles record is always excluded.
	 *
	 * @return \WP_Post[]
	 */
	public static function list_all( string $theme = '', int $limit = 200 ): array {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => max( 1, min( 500, $limit ) ),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);
		if ( '' !== $theme ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => self::THEME_TAX,
					'field'    => 'name',
					'terms'    => sanitize_key( $theme ),
				),
			);
		}

		$out = array();
		foreach ( (array) get_posts( $args ) as $post ) {
			if ( $post instanceof \WP_Post && ! self::is_main_global_styles( $post ) ) {
				$out[] = $post;
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Create / Update / Delete
	// -------------------------------------------------------------------------

	/**
	 * @return int|\WP_Error
	 */
	public static function create( string $theme, string $slug, array $data, array $extras = array() ) {
		$theme = '' !== $theme ? sanitize_key( $theme ) : (string) get_stylesheet();
		$slug  = sanitize_title( $slug );

		if ( '' === $theme ) {
			return new \WP_Error( 'invalid_theme', __( 'Theme is required.', 'acrossai-abilities-manager' ) );
		}
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_slug', __( 'Variation slug is required.', 'acrossai-abilities-manager' ) );
		}
		if ( 0 === strpos( $slug, 'wp-global-styles-' ) ) {
			return new \WP_Error( 'reserved_slug', __( 'Variation slug must not start with "wp-global-styles-" — that prefix is reserved for the main Global Styles record.', 'acrossai-abilities-manager' ) );
		}

		$valid = Global_Styles_Db::validate_data( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$valid = Global_Styles_Db::validate_block_styles( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( self::find_by_slug( $slug, $theme ) ) {
			return new \WP_Error( 'slug_conflict', __( 'A variation with this slug already exists for this theme. Use update instead.', 'acrossai-abilities-manager' ) );
		}

		// Variations share the quoted-value defect the main record had: JSON handed to
		// wp_insert_post() unslashed is wp_unslash()-ed on the way in, so a font stack containing
		// escaped double quotes stored content that no longer parsed. Global_Styles_Writer slashes
		// and then reads the row back to prove it decoded.
		$post_id = Global_Styles_Writer::insert_raw(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( (string) ( $extras['title'] ?? ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) ) ),
				'post_name'    => $slug,
				'post_excerpt' => sanitize_text_field( (string) ( $extras['description'] ?? '' ) ),
			),
			$data
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( (int) $post_id, $theme, self::THEME_TAX );
		return (int) $post_id;
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function update( \WP_Post $post, array $data, bool $merge = true, array $extras = array() ) {
		if ( $merge ) {
			// Never merge into content that does not decode — that is how a single corrupt write
			// silently discarded everything stored before it.
			$existing = Global_Styles_Writer::decode( (string) $post->post_content );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			$new = Global_Styles_Db::deep_merge( $existing, $data );
		} else {
			$new = $data;
		}

		$valid = Global_Styles_Db::validate_data( $new );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$valid = Global_Styles_Db::validate_block_styles( $new );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$update = array();
		if ( array_key_exists( 'title', $extras ) ) {
			$update['post_title'] = sanitize_text_field( (string) $extras['title'] );
		}
		if ( array_key_exists( 'description', $extras ) ) {
			$update['post_excerpt'] = sanitize_text_field( (string) $extras['description'] );
		}
		if ( array_key_exists( 'new_slug', $extras ) && '' !== $extras['new_slug'] ) {
			$new_slug = sanitize_title( (string) $extras['new_slug'] );
			if ( '' === $new_slug ) {
				return new \WP_Error( 'invalid_new_slug', __( 'new_slug is invalid.', 'acrossai-abilities-manager' ) );
			}
			$theme = self::get_post_theme( $post );
			if ( $new_slug !== $post->post_name && self::find_by_slug( $new_slug, $theme ) ) {
				return new \WP_Error( 'new_slug_conflict', __( 'A variation with new_slug already exists for this theme.', 'acrossai-abilities-manager' ) );
			}
			$update['post_name'] = $new_slug;
		}

		$result = Global_Styles_Writer::update_raw( (int) $post->ID, $new, $update );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (int) $result;
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function update_section( \WP_Post $post, string $section, array $section_data ) {
		$section = self::normalize_section( $section );
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			return new \WP_Error(
				'invalid_section',
				/* translators: %s: list of valid sections */
				sprintf( __( 'Section must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::SECTIONS ) )
			);
		}

		$existing = Global_Styles_Writer::decode( (string) $post->post_content );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		foreach ( self::SECTION_PATHS[ $section ] as $path ) {
			$value = Global_Styles_Db::path_get( $section_data, $path );
			if ( null !== $value ) {
				Global_Styles_Db::path_set( $existing, $path, $value );
			}
		}
		return self::update( $post, $existing, false );
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function delete_section( \WP_Post $post, string $section ) {
		$section = self::normalize_section( $section );
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			return new \WP_Error(
				'invalid_section',
				/* translators: %s: list of valid sections */
				sprintf( __( 'Section must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::SECTIONS ) )
			);
		}

		$existing = self::decode_content( $post );
		foreach ( self::SECTION_PATHS[ $section ] as $path ) {
			Global_Styles_Db::path_delete( $existing, $path );
		}
		return self::update( $post, $existing, false );
	}

	/**
	 * Delete.
	 *
	 * @param \WP_Post $post
	 * @return bool
	 */
	public static function delete( \WP_Post $post ): bool {
		$result = wp_delete_post( (int) $post->ID, true );
		return false !== $result && null !== $result;
	}

	// -------------------------------------------------------------------------
	// Read helpers
	// -------------------------------------------------------------------------

	/**
	 * Decode content.
	 *
	 * @param \WP_Post $post
	 * @return array
	 */
	public static function decode_content( \WP_Post $post ): array {
		$content = trim( (string) $post->post_content );
		if ( '' === $content ) {
			return array();
		}
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get post theme.
	 *
	 * @param \WP_Post $post
	 * @return string
	 */
	public static function get_post_theme( \WP_Post $post ): string {
		$terms = wp_get_object_terms( (int) $post->ID, self::THEME_TAX, array( 'fields' => 'names' ) );
		return ! is_wp_error( $terms ) && ! empty( $terms ) ? (string) $terms[0] : '';
	}

	/**
	 * Get section.
	 *
	 * @param \WP_Post $post
	 * @param string $section
	 * @return array
	 */
	public static function get_section( \WP_Post $post, string $section ): array {
		$section = self::normalize_section( $section );
		$data    = self::decode_content( $post );
		$out     = array();
		foreach ( ( self::SECTION_PATHS[ $section ] ?? array() ) as $path ) {
			$value = Global_Styles_Db::path_get( $data, $path );
			if ( null !== $value ) {
				Global_Styles_Db::path_set( $out, $path, $value );
			}
		}
		return $out;
	}

	/**
	 * @return string[]
	 */
	public static function get_customized_sections( \WP_Post $post ): array {
		$data = self::decode_content( $post );
		if ( empty( $data ) ) {
			return array();
		}
		$customized = array();
		foreach ( self::SECTION_PATHS as $section => $paths ) {
			foreach ( $paths as $path ) {
				$value = Global_Styles_Db::path_get( $data, $path );
				if ( null === $value ) {
					continue;
				}
				if ( is_array( $value ) && empty( $value ) ) {
					continue;
				}
				$customized[] = $section;
				break;
			}
		}
		return $customized;
	}

	/**
	 * To row.
	 *
	 * @param \WP_Post $post
	 * @param bool $include_content
	 * @return array
	 */
	public static function to_row( \WP_Post $post, bool $include_content = false ): array {
		$theme = self::get_post_theme( $post );
		$row   = array(
			'source'              => 'db',
			'post_id'             => (int) $post->ID,
			'slug'                => (string) $post->post_name,
			'title'               => (string) $post->post_title,
			'description'         => (string) $post->post_excerpt,
			'theme'               => $theme,
			'is_active_theme'     => $theme === (string) get_stylesheet(),
			'is_active_variation' => self::is_active_variation( $post ),
			'customized_sections' => self::get_customized_sections( $post ),
			'modified'            => (string) $post->post_modified_gmt,
		);

		if ( $include_content ) {
			$row['data'] = self::decode_content( $post );
		}
		return $row;
	}

	/**
	 * Best-effort detection: a variation is considered active when the main
	 * Global Styles record for its theme carries a meta marker pointing at it.
	 * Returns false when nothing claims this variation.
	 */
	public static function is_active_variation( \WP_Post $post ): bool {
		$theme = self::get_post_theme( $post );
		if ( '' === $theme ) {
			return false;
		}
		$main_slug = 'wp-global-styles-' . $theme;
		$mains     = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'name'           => $main_slug,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		if ( empty( $mains ) ) {
			return false;
		}
		$active = (string) get_post_meta( (int) $mains[0]->ID, '_active_style_variation', true );
		return $active === (string) $post->post_name;
	}
}
