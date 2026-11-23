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

defined( 'ABSPATH' ) || exit;

/**
 * Update_Cpt_Item ability class (absorbed).
 */
class Update_Cpt_Item extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/update-cpt-item',
			'args' => array(
				'label'               => __( 'Update CPT Item', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update a custom post type record via wp_update_post(). post_type is validated against the post; only supplied fields are touched.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array( 'type' => 'string' ),
						'allow_protected_post_type' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Write anyway when the post type belongs to a plugin that keeps derived data elsewhere. The response states what was bypassed.', 'acrossai-abilities-manager' ),
						),
						'id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'excerpt'   => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'slug'      => array( 'type' => 'string' ),
						'meta'      => array( 'type' => 'object' ),
						'return_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, the response includes the saved post_content / post_excerpt / post_content_filtered fields. Default false: those large fields are stripped and content_bytes is returned instead.', 'acrossai-abilities-manager' ),
						),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'post_type', 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'blocked_reason' => array( 'type' => 'string' ),
						'post_type'      => array( 'type' => 'string' ),
						'use_instead'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'writes'         => array( 'type' => 'string' ),
						'warnings'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'success'       => array( 'type' => 'boolean' ),
						'id'            => array( 'type' => 'integer' ),
						'item'          => array( 'type' => 'object' ),
						'content_bytes' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'cpt',
						'sub_group_label' => __( 'Custom Post Types', 'acrossai-abilities-manager' ),
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
				'reason' => __( 'For narrow edits, outline first to locate the target block cheaply — the outline is kilobytes even when the item body is hundreds.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~29K tokens vs full item rewrite on a 97 KB body', 'acrossai-abilities-manager' ),
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
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		$id        = (int) ( $input['id'] ?? 0 );

		/*
		 * Feature 120 — refuse before the post lookup, deliberately. For an HPOS order `get_post()`
		 * misses and the caller is told "Item not found for the given post_type", which is both
		 * wrong and unhelpful: the order exists, just not here.
		 */
		$assessment = Protected_Post_Types::assess( $post_type, isset( $input['meta'] ) && array() !== (array) $input['meta'] );

		if ( $assessment['blocked'] && empty( $input['allow_protected_post_type'] ) ) {
			return Protected_Post_Types::refusal( $assessment['verdict'] );
		}

		$warnings = Protected_Post_Types::warnings_for( $assessment['verdict'] );

		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || $post->post_type !== $post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Item not found for the given post_type.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to edit this item.', 'acrossai-abilities-manager' ),
			);
		}

		$args = array( 'ID' => $id );
		foreach ( array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
			'slug'    => 'post_name',
		) as $in => $out ) {
			if ( isset( $input[ $in ] ) ) {
				$args[ $out ] = 'slug' === $in ? sanitize_title( (string) $input[ $in ] ) : sanitize_text_field( (string) $input[ $in ] );
				if ( 'content' === $in ) {
					$args[ $out ] = (string) $input[ $in ];
				}
			}
		}
		if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$args['meta_input'] = $input['meta'];
		}

		$result = wp_update_post( Slash_Input::slash( $args, $input ), true );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
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
			'success'       => true,
			'warnings'      => $warnings,
			'id'            => (int) $result,
			'item'          => $fetched,
			'content_bytes' => $content_bytes,
			/* translators: 1: post type, 2: ID */
			'message'       => sprintf( __( 'Updated %1$s #%2$d.', 'acrossai-abilities-manager' ), $post_type, $result ),
		);
	}
}
