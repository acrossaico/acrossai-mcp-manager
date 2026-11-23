<?php
/**
 * Feature 055 — inspect a post's autosaves.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return the autosaves attached to a post (distinct from revisions).
 *
 * WP core stores autosaves as revision rows whose author matches the
 * intended author of the autosave; only one autosave per post per author
 * exists at a time. This ability returns the flattened list.
 */
class Inspect_Post_Autosaves extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/inspect-post-autosaves',
			'args' => array(
				'label'               => __( 'Inspect Autosaves', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the autosaves attached to a post (distinct from revisions). Only one autosave per post per author exists at a time; this ability flattens them into a list.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'   => array( 'type' => 'boolean' ),
						'post_id'   => array( 'type' => 'integer' ),
						'autosaves' => array( 'type' => 'array' ),
						'message'   => array( 'type' => 'string' ),
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
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
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

		// Feature 055 hardening — per-post cap check + internal-CPT filter
		// (autosaves can leak draft/private content).
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to inspect autosaves for this post.', 'acrossai-abilities-manager' ),
			);
		}
		$post_type_obj = get_post_type_object( (string) $post->post_type );
		if ( ! $post_type_obj instanceof \WP_Post_Type
			|| in_array( $post_type_obj->name, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'This post type is not supported by this ability.', 'acrossai-abilities-manager' ),
			);
		}

		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'check_enabled' => false,
				'post_status'   => 'inherit',
			)
		);
		$autosaves = array();
		if ( is_array( $revisions ) ) {
			foreach ( $revisions as $rev ) {
				if ( ! $rev instanceof \WP_Post ) {
					continue;
				}
				// Autosaves are named "<parent>-autosave-v<n>".
				if ( false === strpos( (string) $rev->post_name, '-autosave' ) ) {
					continue;
				}
				$autosaves[] = array(
					'id'             => (int) $rev->ID,
					'author_id'      => (int) $rev->post_author,
					'modified_gmt'   => (string) $rev->post_modified_gmt,
					'title'          => (string) $rev->post_title,
					'content_length' => strlen( (string) $rev->post_content ),
				);
			}
		}

		return array(
			'success'   => true,
			'post_id'   => $post_id,
			'autosaves' => $autosaves,
			/* translators: 1: autosave count, 2: post ID */
			'message'   => sprintf( __( 'Found %1$d autosave(s) for post #%2$d.', 'acrossai-abilities-manager' ), count( $autosaves ), $post_id ),
		);
	}
}
