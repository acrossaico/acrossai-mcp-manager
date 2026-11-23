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
 * Delete post meta via delete_post_meta(). Works for any meta key. If a value
 * is supplied, only rows matching that value are removed; otherwise every row
 * for the given key is removed.
 */
class Delete_Post_Meta extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/delete-post-meta',
			'args' => array(
				'label'               => __( 'Delete Post Meta', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a post meta row via delete_post_meta(). If a value is supplied, only rows matching that value are removed; otherwise every row for the given key is removed.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'allow_protected_post_type' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Write anyway when the post type belongs to a plugin that keeps derived data elsewhere. The response states what was bypassed.', 'acrossai-abilities-manager' ),
						),
						'key'        => array( 'type' => 'string' ),
						'meta_key'   => array(
							'type'        => 'string',
							'description' => __( 'Alias for "key" (matches WordPress core naming). If both are provided, "key" wins.', 'acrossai-abilities-manager' ),
						),
						'value'      => array(
							'type'        => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ),
							'description' => __( 'Optional. If supplied, only meta rows whose stored value matches will be deleted.', 'acrossai-abilities-manager' ),
						),
						'meta_value' => array(
							'type'        => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ),
							'description' => __( 'Alias for "value" (matches WordPress core naming). If both are provided, "value" wins.', 'acrossai-abilities-manager' ),
						),
					),
					'allOf'                => array(
						array( 'required' => array( 'post_id' ) ),
						array(
							'anyOf' => array(
								array( 'required' => array( 'key' ) ),
								array( 'required' => array( 'meta_key' ) ),
							),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'post_type'      => array( 'type' => 'string' ),
						'writes'         => array( 'type' => 'string' ),
						'use_instead'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'warnings'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'deleted' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
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
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$raw_key = ! empty( $input['key'] ) ? $input['key'] : ( $input['meta_key'] ?? '' );
		$key     = sanitize_text_field( (string) $raw_key );

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'acrossai-abilities-manager' ),
			);
		}

		/*
		 * Feature 120 — a meta write is exactly the case that costs money: it is where a plugin like
		 * WooCommerce keeps the values it derives others from. Measured: writing `_regular_price`
		 * here leaves `_price` and the product lookup table on the OLD price, and saving the product
		 * correctly afterwards does not repair it, because WooCommerce sees the meta already changed
		 * and registers no change at all.
		 */
		$assessment = Protected_Post_Types::assess( (string) get_post_type( $post_id ), true );

		if ( $assessment['blocked'] && empty( $input['allow_protected_post_type'] ) ) {
			return Protected_Post_Types::refusal( $assessment['verdict'] );
		}

		$warnings = Protected_Post_Types::warnings_for( $assessment['verdict'] );
		if ( '' === $key ) {
			return array(
				'success' => false,
				'message' => __( 'Meta key is empty. Pass "key" (or its alias "meta_key").', 'acrossai-abilities-manager' ),
			);
		}

		$has_value = array_key_exists( 'value', $input ) || array_key_exists( 'meta_value', $input );
		$value     = array_key_exists( 'value', $input ) ? $input['value'] : ( $input['meta_value'] ?? '' );

		$deleted = $has_value
			? delete_post_meta( $post_id, $key, $value )
			: delete_post_meta( $post_id, $key );

		return array(
			'success' => true,
			'warnings' => $warnings,
			'deleted' => (bool) $deleted,
			/* translators: 1: meta key, 2: post ID */
			'message' => sprintf( __( 'Deleted meta "%1$s" on post #%2$d.', 'acrossai-abilities-manager' ), $key, $post_id ),
		);
	}
}
