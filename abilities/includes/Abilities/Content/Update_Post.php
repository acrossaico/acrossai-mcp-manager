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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Protected_Post_Types;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Post_Meta_Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Update an existing post (any post type) via wp_update_post().
 * All fields besides "id" are optional — only the supplied fields are touched.
 */
class Update_Post extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/update-post',
			'args' => array(
				'label'               => __( 'Update Post', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update an existing post (any post type) via wp_update_post(). Only the supplied fields are changed. Refuses non-writable post types and strips protected meta keys unless allow-listed.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'allow_protected_post_type' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Write anyway when the post type belongs to a plugin that keeps derived data elsewhere. The response states what was bypassed.', 'acrossai-abilities-manager' ),
						),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'author'  => array( 'type' => 'integer' ),
						'date'    => array( 'type' => 'string' ),
						'meta'    => array( 'type' => 'object' ),
						'return_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, the response includes the saved post_content / post_excerpt / post_content_filtered fields. Default false: those large fields are stripped and content_bytes is returned instead, so a "one-word edit" round-trip does not echo the whole post body back through the tunnel.', 'acrossai-abilities-manager' ),
						),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'           => array( 'type' => 'boolean' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'post_type'      => array( 'type' => 'string' ),
						'writes'         => array( 'type' => 'string' ),
						'use_instead'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'warnings'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'id'                => array( 'type' => 'integer' ),
						'post'              => array( 'type' => 'object' ),
						'content_bytes'     => array( 'type' => 'integer' ),
						'dropped_meta_keys' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'blocked_reason'    => array( 'type' => 'string' ),
						'message'           => array( 'type' => 'string' ),
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
						'destructive' => false,
						'idempotent'  => true,
					),
					'input_flags'  => Slash_Input::meta_flags(),
				),
			),
		);
	}

	/**
	 * Feature 095 — hint that narrow edits can be done far more cheaply
	 * through the block-outline + surgical-write pair instead of shipping
	 * a full replacement post_content.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'content/inspect-post-builder',
				'reason' => __( 'Run first on an existing post. A page built with Elementor or another page builder keeps its layout outside post_content, so this write can report success, change nothing a visitor sees, and be reverted the next time the page is saved in the builder.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'blocks/outline-post-blocks',
				'reason' => __( 'For narrow edits, outline first to locate the target block cheaply — the outline is kilobytes even when the post is hundreds.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~29K tokens vs full post rewrite on a 97 KB body', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'blocks/update-post-block',
				'reason' => __( 'Update just the located block without re-serializing the whole post_content.', 'acrossai-abilities-manager' ),
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
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! ( $post instanceof \WP_Post ) ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'acrossai-abilities-manager' ),
			);
		}

		// Writable-post-type gate: mirrors WP REST writability convention.
		$pt_obj = get_post_type_object( (string) $post->post_type );

		/*
		 * Feature 120 — the same guard the cpt-item writers apply. Without it this ability is a
		 * bypass for them: `content/create-cpt-item` refuses `product` + `_regular_price`, and an
		 * identical payload here performed the identical corrupting write one call away.
		 */
		$assessment = Protected_Post_Types::assess( (string) $post->post_type, isset( $input['meta'] ) && array() !== (array) $input['meta'] );

		if ( $assessment['blocked'] && empty( $input['allow_protected_post_type'] ) ) {
			return Protected_Post_Types::refusal( $assessment['verdict'] );
		}

		$warnings = Protected_Post_Types::warnings_for( $assessment['verdict'] );
		if ( null === $pt_obj || ! ( ! empty( $pt_obj->public ) || ! empty( $pt_obj->show_in_rest ) ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'non_writable_post_type',
				/* translators: %s: post type name */
				'message'        => sprintf( __( 'Post type "%s" is not writable via this ability.', 'acrossai-abilities-manager' ), (string) $post->post_type ),
			);
		}

		// publish_posts capability gate on any status change into a public state.
		if ( isset( $input['status'] ) ) {
			$requested_status = sanitize_key( (string) $input['status'] );
			$status_obj       = get_post_status_object( $requested_status );
			if ( $status_obj && ! empty( $status_obj->public )
				&& ! current_user_can( (string) $pt_obj->cap->publish_posts ) ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'publish_cap_required',
					/* translators: 1: status, 2: post type */
					'message'        => sprintf( __( 'You do not have permission to set status "%1$s" on post type "%2$s".', 'acrossai-abilities-manager' ), $requested_status, (string) $post->post_type ),
				);
			}
		}

		// edit_others_posts capability gate on author changes.
		if ( isset( $input['author'] )
			&& (int) $input['author'] !== (int) get_current_user_id()
			&& ! current_user_can( (string) $pt_obj->cap->edit_others_posts ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'edit_others_posts_required',
				/* translators: %s: post type name */
				'message'        => sprintf( __( 'You do not have permission to change the author on post type "%s".', 'acrossai-abilities-manager' ), (string) $post->post_type ),
			);
		}

		$args = array( 'ID' => $id );
		if ( isset( $input['title'] ) ) {
			$args['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$args['post_content'] = (string) $input['content'];
		}
		if ( isset( $input['excerpt'] ) ) {
			$args['post_excerpt'] = (string) $input['excerpt'];
		}
		if ( isset( $input['status'] ) ) {
			$args['post_status'] = sanitize_key( (string) $input['status'] );
		}
		if ( isset( $input['slug'] ) ) {
			$args['post_name'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['author'] ) ) {
			$args['post_author'] = (int) $input['author'];
		}
		if ( isset( $input['date'] ) ) {
			$args['post_date'] = (string) $input['date'];
		}

		/*
		 * #274 — write caller meta, refusing only genuinely-internal `_`-prefixed keys.
		 *
		 * This replaced a broader strip that also dropped every protected-meta key (the WP custom-
		 * fields guard), which caught writable plugin meta like `rank_math_*` and lost it silently
		 * while reporting success. See Post_Meta_Policy for why that rule was wrong and why
		 * `rank_math_*` now writes. Anything still refused is reported in BOTH `dropped_meta_keys`
		 * and `warnings`, so a caller that only reads `success` still sees the reason.
		 */
		$dropped_meta_keys = array();
		if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$partition         = Post_Meta_Policy::partition( $input['meta'] );
			$dropped_meta_keys = $partition['dropped'];

			if ( array() !== $partition['write'] ) {
				$args['meta_input'] = $partition['write'];
			}

			foreach ( $dropped_meta_keys as $dropped_key ) {
				$warnings[] = Post_Meta_Policy::drop_warning( $dropped_key );
			}
		}

		$result = wp_update_post( Slash_Input::slash( $args, $input ), true );
		if ( is_wp_error( $result ) ) {
			return array(
				'success'           => false,
				'dropped_meta_keys' => $dropped_meta_keys,
				'message'           => $result->get_error_message(),
			);
		}

		$fetched        = (array) get_post( (int) $result, ARRAY_A );
		$content_bytes  = strlen( (string) ( $fetched['post_content'] ?? '' ) );
		$return_content = ! empty( $input['return_content'] );
		if ( ! $return_content ) {
			unset(
				$fetched['post_content'],
				$fetched['post_content_filtered'],
				$fetched['post_excerpt']
			);
		}

		return array(
			'success'           => true,

			'warnings' => $warnings,
			'id'                => (int) $result,
			'post'              => $fetched,
			'content_bytes'     => $content_bytes,
			'dropped_meta_keys' => $dropped_meta_keys,
			/* translators: %d: post ID */
			'message'           => sprintf( __( 'Updated post #%d.', 'acrossai-abilities-manager' ), $result ),
		);
	}
}
