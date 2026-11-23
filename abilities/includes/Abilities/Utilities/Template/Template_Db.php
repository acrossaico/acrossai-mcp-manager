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
 * Database storage for wp_template posts. Mirrors Template_Part_Db but
 * targets full block templates (index, single, archive, 404, search, etc.).
 *
 * Each DB template is scoped to a theme via the wp_theme taxonomy. WordPress
 * treats the DB version as authoritative — file versions act as fallbacks.
 */
final class Template_Db {

	public const POST_TYPE = 'wp_template';
	public const THEME_TAX = 'wp_theme';

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
	 * Finds a DB template by slug, optionally scoped to a theme.
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
	 * Lists DB templates, optionally filtered by theme.
	 *
	 * @return \WP_Post[]
	 */
	public static function list_all( string $theme = '', int $limit = 200 ): array {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
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

		return (array) get_posts( $args );
	}

	/**
	 * Creates a DB template. Validates content and slug uniqueness.
	 *
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function create( array $data ) {
		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_slug', __( 'Template slug is required.', 'acrossai-abilities-manager' ) );
		}

		$theme = '' !== ( $data['theme'] ?? '' )
			? sanitize_key( (string) $data['theme'] )
			: get_stylesheet();

		$content = (string) ( $data['content'] ?? '' );
		if ( ! self::valid_content( $content ) ) {
			return new \WP_Error( 'invalid_content', __( 'Template content cannot be empty.', 'acrossai-abilities-manager' ) );
		}

		if ( self::find_by_slug( $slug, $theme ) ) {
			return new \WP_Error( 'slug_conflict', __( 'A database template with this slug already exists for this theme.', 'acrossai-abilities-manager' ) );
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

		return (int) $post_id;
	}

	/**
	 * Updates a DB template. Only fields present in $data are touched.
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
				return new \WP_Error( 'invalid_content', __( 'Template content cannot be empty.', 'acrossai-abilities-manager' ) );
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
				return new \WP_Error( 'new_slug_conflict', __( 'A database template with new_slug already exists for this theme.', 'acrossai-abilities-manager' ) );
			}
			$update['post_name'] = $new_slug;
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
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
