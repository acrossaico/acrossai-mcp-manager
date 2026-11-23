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
 * Database storage for wp_template_part posts. Mirrors Pattern_Db.
 *
 * Each DB template part is scoped to a theme via the wp_theme taxonomy and
 * to an area (header/footer/sidebar/uncategorized) via wp_template_part_area.
 * WordPress treats the DB version as authoritative — file versions act as
 * fallbacks.
 */
final class Template_Part_Db {

	public const POST_TYPE = 'wp_template_part';
	public const AREA_TAX  = 'wp_template_part_area';
	public const THEME_TAX = 'wp_theme';
	public const AREAS     = array( 'header', 'footer', 'sidebar', 'uncategorized' );

	/**
	 * Valid areas.
	 *
	 * @return array
	 */
	public static function valid_areas(): array {
		return self::AREAS;
	}

	/**
	 * Valid area.
	 *
	 * @param string $area
	 * @return bool
	 */
	public static function valid_area( string $area ): bool {
		return in_array( $area, self::AREAS, true );
	}

	/**
	 * Valid content.
	 *
	 * @param string $content
	 * @return bool
	 */
	public static function valid_content( string $content ): bool {
		return '' !== trim( $content );
	}

	/**
	 * Normalize area.
	 *
	 * @param string $area
	 * @return string
	 */
	public static function normalize_area( string $area ): string {
		$area = strtolower( trim( $area ) );
		return '' === $area ? 'uncategorized' : $area;
	}

	/**
	 * Finds a DB template part by slug, optionally scoped to a theme.
	 */
	public static function find_by_slug( string $slug, string $theme = '' ): ?\WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'name'           => $slug,
			'posts_per_page' => 1,
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

		$posts = get_posts( $args );
		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Lists DB template parts, optionally filtered by theme and/or area.
	 *
	 * @return \WP_Post[]
	 */
	public static function list_all( string $theme = '', string $area = '', int $limit = 200 ): array {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => max( 1, min( 500, $limit ) ),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$tax_query = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		if ( '' !== $theme ) {
			$tax_query[] = array(
				'taxonomy' => self::THEME_TAX,
				'field'    => 'name',
				'terms'    => sanitize_key( $theme ),
			);
		}
		if ( '' !== $area && self::valid_area( $area ) ) {
			$tax_query[] = array(
				'taxonomy' => self::AREA_TAX,
				'field'    => 'name',
				'terms'    => $area,
			);
		}
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		return (array) get_posts( $args );
	}

	/**
	 * Creates a DB template part. Validates content, area, and slug uniqueness.
	 *
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function create( array $data ) {
		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_slug', __( 'Template part slug is required.', 'acrossai-abilities-manager' ) );
		}

		$theme = '' !== ( $data['theme'] ?? '' )
			? sanitize_key( (string) $data['theme'] )
			: get_stylesheet();

		$area = self::normalize_area( (string) ( $data['area'] ?? 'uncategorized' ) );
		if ( ! self::valid_area( $area ) ) {
			return new \WP_Error(
				'invalid_area',
				/* translators: %s: list of valid areas */
				sprintf( __( 'Area must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::AREAS ) )
			);
		}

		$content = (string) ( $data['content'] ?? '' );
		if ( ! self::valid_content( $content ) ) {
			return new \WP_Error( 'invalid_content', __( 'Template part content cannot be empty.', 'acrossai-abilities-manager' ) );
		}

		if ( self::find_by_slug( $slug, $theme ) ) {
			return new \WP_Error( 'slug_conflict', __( 'A database template part with this slug already exists for this theme.', 'acrossai-abilities-manager' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => self::sanitize_status( $data['status'] ?? 'publish' ),
				'post_title'   => sanitize_text_field( (string) ( $data['title'] ?? $slug ) ),
				'post_name'    => $slug,
				'post_excerpt' => sanitize_text_field( (string) ( $data['description'] ?? '' ) ),
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( (int) $post_id, $theme, self::THEME_TAX );
		wp_set_object_terms( (int) $post_id, $area, self::AREA_TAX );

		return (int) $post_id;
	}

	/**
	 * Updates a DB template part. Only fields present in $data are touched.
	 *
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function update( \WP_Post $post, array $data ) {
		$update = array( 'ID' => (int) $post->ID );

		if ( array_key_exists( 'title', $data ) ) {
			$update['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$update['post_excerpt'] = sanitize_text_field( (string) $data['description'] );
		}
		if ( array_key_exists( 'content', $data ) ) {
			$content = (string) $data['content'];
			if ( ! self::valid_content( $content ) ) {
				return new \WP_Error( 'invalid_content', __( 'Template part content cannot be empty.', 'acrossai-abilities-manager' ) );
			}
			$update['post_content'] = $content;
		}
		if ( array_key_exists( 'status', $data ) ) {
			$update['post_status'] = self::sanitize_status( (string) $data['status'] );
		}

		if ( array_key_exists( 'new_slug', $data ) && '' !== $data['new_slug'] ) {
			$new_slug = sanitize_title( (string) $data['new_slug'] );
			if ( '' === $new_slug ) {
				return new \WP_Error( 'invalid_new_slug', __( 'new_slug is invalid.', 'acrossai-abilities-manager' ) );
			}
			$theme = self::get_post_theme( $post );
			if ( $new_slug !== $post->post_name && self::find_by_slug( $new_slug, $theme ) ) {
				return new \WP_Error( 'new_slug_conflict', __( 'A database template part with new_slug already exists for this theme.', 'acrossai-abilities-manager' ) );
			}
			$update['post_name'] = $new_slug;
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'area', $data ) ) {
			$area = self::normalize_area( (string) $data['area'] );
			if ( ! self::valid_area( $area ) ) {
				return new \WP_Error(
					'invalid_area',
					/* translators: %s: list of valid areas */
					sprintf( __( 'Area must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::AREAS ) )
				);
			}
			wp_set_object_terms( (int) $post->ID, $area, self::AREA_TAX );
		}

		if ( array_key_exists( 'theme', $data ) && '' !== $data['theme'] ) {
			wp_set_object_terms( (int) $post->ID, sanitize_key( (string) $data['theme'] ), self::THEME_TAX );
		}

		return (int) $post->ID;
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

	/**
	 * To row.
	 *
	 * @param \WP_Post $post
	 * @return array
	 */
	public static function to_row( \WP_Post $post ): array {
		$theme = self::get_post_theme( $post );
		$slug  = (string) $post->post_name;

		return array(
			'source'      => 'db',
			'slug'        => $slug,
			'full_slug'   => '' !== $theme ? $theme . '//' . $slug : $slug,
			'post_id'     => (int) $post->ID,
			'status'      => (string) $post->post_status,
			'title'       => (string) $post->post_title,
			'description' => (string) $post->post_excerpt,
			'content'     => (string) $post->post_content,
			'theme'       => $theme,
			'area'        => self::get_post_area( $post ),
			'modified'    => (string) $post->post_modified_gmt,
		);
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
	 * Get post area.
	 *
	 * @param \WP_Post $post
	 * @return string
	 */
	public static function get_post_area( \WP_Post $post ): string {
		$terms = wp_get_object_terms( (int) $post->ID, self::AREA_TAX, array( 'fields' => 'names' ) );
		return ! is_wp_error( $terms ) && ! empty( $terms ) ? (string) $terms[0] : 'uncategorized';
	}

	/**
	 * Scans block templates (wp_template) for references to a template part slug.
	 * Detects the Site-Editor block markup pattern: <!-- wp:template-part {"slug":"…"} /-->.
	 *
	 * @return array<int, array{slug:string, title:string, source:string}>
	 */
	public static function find_templates_using( string $bare_slug, string $theme = '' ): array {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return array();
		}

		$bare_slug = sanitize_title( $bare_slug );
		if ( '' === $bare_slug ) {
			return array();
		}

		$query = array();
		if ( '' !== $theme ) {
			$query['theme'] = sanitize_key( $theme );
		}

		$templates = get_block_templates( $query, 'wp_template' );
		$needle    = '"slug":"' . $bare_slug . '"';
		$matches   = array();

		foreach ( $templates as $tpl ) {
			$content = (string) ( $tpl->content ?? '' );
			if ( '' !== $content && false !== strpos( $content, $needle ) ) {
				$matches[] = array(
					'slug'   => (string) ( $tpl->slug ?? '' ),
					'title'  => is_object( $tpl->title ?? null ) ? (string) ( $tpl->title->rendered ?? '' ) : (string) ( $tpl->title ?? '' ),
					'source' => (string) ( $tpl->source ?? '' ),
				);
			}
		}

		return $matches;
	}

	/**
	 * Sanitize status.
	 *
	 * @param mixed $status
	 * @return string
	 */
	private static function sanitize_status( $status ): string {
		$allowed = array( 'publish', 'draft', 'private', 'pending' );
		$status  = is_string( $status ) ? $status : 'publish';
		return in_array( $status, $allowed, true ) ? $status : 'publish';
	}
}
