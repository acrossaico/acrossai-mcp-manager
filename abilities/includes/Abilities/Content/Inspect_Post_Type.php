<?php
/**
 * Feature 120 — whether a post type's row is the whole truth.
 *
 * The read surface for {@see Protected_Post_Types}, exactly as `content/inspect-post-builder` is for
 * `Post_Builder_Detector`. Answer it before writing a post type you do not recognise.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Protected_Post_Types;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether the generic content writers are the right tool for a post type.
 *
 * @since 0.0.34
 */
class Inspect_Post_Type extends Ability_Definition {

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/inspect-post-type',
			'args' => array(
				'label'               => __( 'Inspect Post Type', 'acrossai-abilities-manager' ),
				'description'         => __( 'Report whether a post type\'s row in the posts table is the whole truth, and whether the content abilities will write it correctly. Some plugins keep the records elsewhere, or derive other values from the row that a plain write does not update — in both cases a write reports success and the site carries on using the old data. Run this before writing a post type you do not recognise. Returns a three-way verdict, what goes stale, and which abilities to use instead.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Post type slug, for example product or shop_order.', 'acrossai-abilities-manager' ),
						),
						'post_id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Alternative to post_type: inspect whatever type this post is.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'post_type'    => array( 'type' => 'string' ),
						'owner'        => array( 'type' => 'string' ),
						'owner_active' => array( 'type' => 'boolean' ),
						'writes'       => array(
							'type'        => 'string',
							'description' => __( 'applies: ordinary, the content abilities are correct. incomplete: the write lands but derived data goes stale. discarded: the row is not what the site reads and the write is eventually deleted.', 'acrossai-abilities-manager' ),
						),
						'authority'    => array( 'type' => 'string' ),
						'goes_stale'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'use_instead'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'guidance'     => array( 'type' => 'string' ),
						'note'         => array( 'type' => 'string' ),
						'message'      => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'content',
						'sub_group' => 'posts',
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
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		$post_id   = (int) ( $input['post_id'] ?? 0 );

		if ( '' === $post_type && $post_id > 0 ) {
			$resolved = get_post_type( $post_id );

			// Distinguished deliberately: get_post_type() returns false for a post that does not
			// exist, and reporting "Supply either post_type or post_id" to a caller who supplied
			// post_id sends them looking for a mistake they did not make.
			if ( false === $resolved ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: post ID. */
						__( 'No post with ID %d.', 'acrossai-abilities-manager' ),
						$post_id
					),
				);
			}

			$post_type = (string) $resolved;
		}

		if ( '' === $post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Supply either post_type or post_id.', 'acrossai-abilities-manager' ),
			);
		}

		$verdict = Protected_Post_Types::inspect( $post_type );

		return array_merge(
			array( 'success' => true ),
			$verdict,
			array( 'message' => (string) $verdict['guidance'] )
		);
	}
}
