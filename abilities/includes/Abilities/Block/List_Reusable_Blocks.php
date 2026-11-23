<?php
/**
 * Feature 055 — list reusable blocks (wp_block CPT rows).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Enumerate reusable blocks (`post_type=wp_block`).
 *
 * Distinct from block patterns: reusable blocks are editable posts backed
 * by the wp_block CPT, whereas patterns are code-registered.
 */
class List_Reusable_Blocks extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/list-reusable-blocks',
			'args' => array(
				'label'               => __( 'List Reusable Blocks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Enumerate reusable blocks (post_type=wp_block). Distinct from block patterns: reusable blocks are editable posts backed by the wp_block CPT, whereas patterns are code-registered.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'items'   => array( 'type' => 'array' ),
						'total'   => array( 'type' => 'integer' ),
						'page'    => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'reusable',
						'sub_group_label' => __( 'Reusable Blocks', 'acrossai-abilities-manager' ),
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
		$per_page = max( 1, min( 200, (int) ( $input['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );

		$query = new \WP_Query(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$items[] = array(
				'id'         => (int) $post->ID,
				'title'      => sanitize_text_field( (string) $post->post_title ),
				'slug'       => sanitize_title( (string) $post->post_name ),
				'status'     => sanitize_key( (string) $post->post_status ),
				'modified'   => sanitize_text_field( (string) $post->post_modified_gmt ),
				'has_content' => '' !== trim( (string) $post->post_content ),
			);
		}

		return array(
			'success' => true,
			'items'   => $items,
			'total'   => (int) $query->found_posts,
			'page'    => $page,
			/* translators: 1: page count, 2: total */
			'message' => sprintf( __( 'Returned %1$d of %2$d reusable blocks.', 'acrossai-abilities-manager' ), count( $items ), (int) $query->found_posts ),
		);
	}
}
