<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch a single post (any post type) by ID.
 */
class Get_Post extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/get-post',
			'args' => array(
				'label'               => __( 'Get Post', 'acrossai-abilities-manager' ),
				'description'         => __( 'Fetch a post (any post type) by ID via get_post(). Returns the raw post row plus derived fields (terms, non-protected meta, featured image, permalink, edit link, author).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Optional: error if the post does not match this type.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'post'           => array( 'type' => 'object' ),
						'terms'          => array( 'type' => 'object' ),
						'meta'           => array( 'type' => 'object' ),
						'featured_image' => array( 'type' => array( 'object', 'null' ) ),
						'permalink'      => array( 'type' => 'string' ),
						'edit_link'      => array( 'type' => 'string' ),
						'author'         => array( 'type' => 'object' ),
						'message'        => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'posts',
						'sub_group_label' => __( 'Posts', 'acrossai-abilities-manager' ),
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
	 * Feature 095 follow-up — hint that block-structure lookups are far cheaper
	 * via the outline ability than fetching the whole post.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'blocks/outline-post-blocks',
				'reason' => __( 'If you only need the block structure or want to locate a specific block, outline returns paths + short text previews without the full post_content — kilobytes instead of hundreds.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~20x fewer tokens on a 97 KB body', 'acrossai-abilities-manager' ),
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
		$id   = (int) ( $input['id'] ?? 0 );
		$post = $id > 0 ? get_post( $id, ARRAY_A ) : null;
		if ( ! $post ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'acrossai-abilities-manager' ),
			);
		}

		$expected = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		if ( '' !== $expected && $expected !== $post['post_type'] ) {
			return array(
				'success' => false,
				/* translators: 1: requested post type, 2: actual post type */
				'message' => sprintf( __( 'Post is not of type "%1$s" (actual: "%2$s").', 'acrossai-abilities-manager' ), $expected, $post['post_type'] ),
			);
		}

		// Terms grouped by taxonomy.
		$terms      = array();
		$taxonomies = get_object_taxonomies( (string) $post['post_type'] );
		foreach ( (array) $taxonomies as $tax ) {
			$t_objs = get_the_terms( $id, (string) $tax );
			if ( is_array( $t_objs ) ) {
				$terms[ (string) $tax ] = array_values(
					array_map(
						static function ( $t ): array {
							return array(
								'term_id' => (int) $t->term_id,
								'name'    => (string) $t->name,
								'slug'    => (string) $t->slug,
							);
						},
						$t_objs
					)
				);
			} else {
				$terms[ (string) $tax ] = array();
			}
		}

		// Non-protected meta.
		$allowed = (array) apply_filters( 'acrossai_allowed_protected_meta', array() );
		$raw     = (array) get_post_meta( $id );
		$meta    = array();
		foreach ( $raw as $key => $vals ) {
			$key_str = (string) $key;
			$is_prot = str_starts_with( $key_str, '_' ) || is_protected_meta( $key_str, 'post' );
			if ( $is_prot && ! in_array( $key_str, $allowed, true ) ) {
				continue;
			}
			$vals_arr = (array) $vals;
			if ( 1 === count( $vals_arr ) ) {
				$meta[ $key_str ] = maybe_unserialize( reset( $vals_arr ) );
			} else {
				$meta[ $key_str ] = array_map( 'maybe_unserialize', $vals_arr );
			}
		}

		// Featured image.
		$thumb_id = (int) get_post_thumbnail_id( $id );
		if ( $thumb_id > 0 ) {
			$featured_image = array(
				'id'  => $thumb_id,
				'url' => (string) wp_get_attachment_image_url( $thumb_id, 'full' ),
				'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
			);
		} else {
			$featured_image = null;
		}

		// Author.
		$author_id  = (int) $post['post_author'];
		$author_obj = get_userdata( $author_id );
		$author     = array(
			'id'   => $author_id,
			'name' => $author_obj ? (string) $author_obj->display_name : '',
		);

		return array(
			'success'        => true,
			'post'           => $post,
			'terms'          => $terms,
			'meta'           => $meta,
			'featured_image' => $featured_image,
			'permalink'      => (string) get_permalink( $id ),
			'edit_link'      => (string) get_edit_post_link( $id, 'raw' ),
			'author'         => $author,
		);
	}
}
