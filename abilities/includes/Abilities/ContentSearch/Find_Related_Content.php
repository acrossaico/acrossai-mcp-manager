<?php
/**
 * Feature 055 — find posts related to a given post via shared terms.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContentSearch
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContentSearch;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return posts related to a given post via shared taxonomy terms
 * (categories + tags by default).
 */
class Find_Related_Content extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/find-related-content',
			'args' => array(
				'label'               => __( 'Find Related Content', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return posts related to a given post via shared taxonomy terms (categories + tags by default). Fallback implementation — no learned relevance model.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 5,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'related' => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'find',
						'sub_group_label' => __( 'Find', 'acrossai-abilities-manager' ),
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
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$post_id  = absint( $input['post_id'] ?? 0 );
		$per_page = max( 1, min( 50, (int) ( $input['per_page'] ?? 5 ) ) );
		if ( $post_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A valid post_id is required.', 'acrossai-abilities-manager' ),
			);
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'acrossai-abilities-manager' ),
			);
		}
		// Feature 055 hardening — caller must be able to read the source post.
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to read this post.', 'acrossai-abilities-manager' ),
			);
		}

		$cat_ids = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$tag_ids = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
		$cat_ids = is_array( $cat_ids ) ? array_map( 'intval', $cat_ids ) : array();
		$tag_ids = is_array( $tag_ids ) ? array_map( 'intval', $tag_ids ) : array();

		$tax_query = array( 'relation' => 'OR' );
		if ( array() !== $cat_ids ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'terms'    => $cat_ids,
			);
		}
		if ( array() !== $tag_ids ) {
			$tax_query[] = array(
				'taxonomy' => 'post_tag',
				'terms'    => $tag_ids,
			);
		}
		if ( 1 === count( $tax_query ) ) {
			return array(
				'success' => true,
				'related' => array(),
				'message' => __( 'Source post has no categories or tags; no relatives to find.', 'acrossai-abilities-manager' ),
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'post__not_in'   => array( $post_id ),
				'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			)
		);

		$related = array();
		foreach ( $query->posts as $rel ) {
			if ( ! $rel instanceof \WP_Post ) {
				continue;
			}
			if ( ! current_user_can( 'read_post', (int) $rel->ID ) ) {
				continue;
			}
			$related[] = array(
				'id'     => (int) $rel->ID,
				'title'  => sanitize_text_field( (string) $rel->post_title ),
				'url'    => (string) get_permalink( $rel ),
				'reason' => 'shared-terms',
			);
		}

		return array(
			'success' => true,
			'related' => $related,
			/* translators: 1: count, 2: source post ID */
			'message' => sprintf( __( 'Found %1$d related post(s) for post #%2$d.', 'acrossai-abilities-manager' ), count( $related ), $post_id ),
		);
	}
}
