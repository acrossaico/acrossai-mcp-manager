<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Media
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Media;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List_Media ability class (absorbed).
 */
class List_Media extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'media/list-media',
			'args' => array(
				'label'               => __( 'List Media', 'acrossai-abilities-manager' ),
				'description'         => __( 'List media items via GET /wp/v2/media. Supports search (across title, caption, description, and alt-text), pagination, and a mime_type filter.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-media',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'page'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 10,
						),
						'search'    => array( 'type' => 'string' ),
						'mime_type' => array( 'type' => 'string' ),
						'parent'    => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'media'   => array( 'type' => 'array' ),
						'total'   => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'manage',
						'sub_group_label' => __( 'Manage', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Feature 095 follow-up — hint that known-ID reads should skip the
	 * pagination entirely.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'media/get-media',
				'reason' => __( 'If you already know the attachment ID, a targeted get-media read is far cheaper than paging through the media library.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~10-15x fewer tokens for known-ID reads', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'media/generate-image',
				'reason' => __( 'Need an image the library does not have yet? Generate one from a text prompt and add it to the library in a single call.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => false,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = sanitize_mime_type( (string) $input['mime_type'] );
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		// Widened search: union the WP_Query `s` match (title/caption/description)
		// with a meta_query id-only lookup against _wp_attachment_image_alt so
		// alt-text-only matches are included.
		if ( ! empty( $input['search'] ) ) {
			$search = sanitize_text_field( (string) $input['search'] );

			$base_filters = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			);
			if ( ! empty( $args['post_mime_type'] ) ) {
				$base_filters['post_mime_type'] = $args['post_mime_type'];
			}
			if ( isset( $args['post_parent'] ) ) {
				$base_filters['post_parent'] = $args['post_parent'];
			}

			$args_text_ids       = $base_filters;
			$args_text_ids['s']  = $search;
			$args_alt_ids        = $base_filters;
			$args_alt_ids['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => $search,
					'compare' => 'LIKE',
				),
			);

			$text_ids = get_posts( $args_text_ids );
			$alt_ids  = get_posts( $args_alt_ids );

			$ids = array_values(
				array_unique(
					array_map( 'absint', array_merge( (array) $text_ids, (array) $alt_ids ) )
				)
			);

			if ( empty( $ids ) ) {
				return array(
					'success' => true,
					'media'   => array(),
					'total'   => 0,
				);
			}

			$args['post__in'] = $ids;
		}

		$query = new \WP_Query( $args );

		$formatted = array_map(
			array( Media_Formatter::class, 'to_array' ),
			array_values(
				array_filter(
					$query->posts,
					static function ( $p ): bool {
						return $p instanceof \WP_Post;
					}
				)
			)
		);

		return array(
			'success' => true,
			'media'   => $formatted,
			'total'   => (int) $query->found_posts,
		);
	}
}
