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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Protected_Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Delete a post (any post type). Defaults to a trash; pass force=true to bypass
 * trash and remove permanently.
 */
class Delete_Post extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/delete-post',
			'args' => array(
				'label'               => __( 'Delete Post', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a post (any post type) via wp_delete_post(). Defaults to trash; pass force=true to delete permanently. When a published post is force-deleted, the response includes a suggested_redirect target for the dead URL.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'allow_protected_post_type' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Write anyway when the post type belongs to a plugin that keeps derived data elsewhere. The response states what was bypassed.', 'acrossai-abilities-manager' ),
						),
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'            => array( 'type' => 'boolean' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'post_type'      => array( 'type' => 'string' ),
						'writes'         => array( 'type' => 'string' ),
						'use_instead'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'warnings'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'id'                 => array( 'type' => 'integer' ),
						'force'              => array( 'type' => 'boolean' ),
						'suggested_redirect' => array( 'type' => 'object' ),
						'message'            => array( 'type' => 'string' ),
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
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
				),
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
		$id    = (int) ( $input['id'] ?? 0 );
		$force = ! empty( $input['force'] );

		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! ( $post instanceof \WP_Post ) ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! current_user_can( 'delete_post', $id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to delete this post.', 'acrossai-abilities-manager' ),
			);
		}

		// Snapshot state that becomes unavailable after the delete so we can
		// compute a suggested_redirect for published-post force deletes.
		$was_publish = ( 'publish' === (string) $post->post_status );
		$permalink   = (string) get_permalink( $post );
		$parent_id   = (int) $post->post_parent;
		$post_type   = (string) $post->post_type;

		/*
		 * Feature 120 — the same guard the cpt-item writers apply. Without it this ability is a
		 * bypass for them: `content/create-cpt-item` refuses `product` + `_regular_price`, and an
		 * identical payload here performed the identical corrupting write one call away.
		 */
		$assessment = Protected_Post_Types::assess( $post_type, false );

		if ( $assessment['blocked'] && empty( $input['allow_protected_post_type'] ) ) {
			return Protected_Post_Types::refusal( $assessment['verdict'] );
		}

		$warnings = Protected_Post_Types::warnings_for( $assessment['verdict'] );

		$result = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		if ( ! $result ) {
			return array(
				'success' => false,
				'message' => __( 'Could not delete the post.', 'acrossai-abilities-manager' ),
			);
		}

		$response = array(
			'success' => true,

			'warnings' => $warnings,
			'id'      => $id,
			'force'   => $force,
			'message' => $force
				/* translators: %d: post ID */
				? sprintf( __( 'Permanently deleted post #%d.', 'acrossai-abilities-manager' ), $id )
				/* translators: %d: post ID */
				: sprintf( __( 'Moved post #%d to trash.', 'acrossai-abilities-manager' ), $id ),
		);

		if ( $force && $was_publish ) {
			$target = '';
			if ( $parent_id > 0 ) {
				$parent_post = get_post( $parent_id );
				if ( $parent_post instanceof \WP_Post && 'publish' === (string) $parent_post->post_status ) {
					$target = (string) get_permalink( $parent_post );
				}
			}
			if ( '' === $target ) {
				$archive = get_post_type_archive_link( $post_type );
				if ( is_string( $archive ) && '' !== $archive ) {
					$target = $archive;
				}
			}
			if ( '' === $target ) {
				$target = (string) home_url( '/' );
			}

			$response['suggested_redirect'] = array(
				'from' => $permalink,
				'to'   => $target,
			);
		}

		return $response;
	}
}
