<?php
/**
 * Feature 064 — Append a new post-meta row without replacing existing rows.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.0.23
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Protected_Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Append a new post-meta row via add_post_meta(). Complements the existing
 * update-post-meta (replace) and delete-post-meta (remove) writers. Mirrors
 * the input-schema surface of Update_Post_Meta.php verbatim (accepts both
 * `key`/`value` WP-CLI aliases and `meta_key`/`meta_value` WP-core aliases)
 * plus a `unique` flag that maps to WordPress core `add_post_meta( ..., $unique )`.
 */
class Add_Post_Meta extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/add-post-meta',
			'args' => array(
				'label'               => __( 'Add Post Meta', 'acrossai-abilities-manager' ),
				'description'         => __( 'Append a new post-meta row via add_post_meta() — additive, does not replace existing rows for the same key. Set unique:true to refuse the append if any row already exists for the (post_id, key) pair (matches WordPress core add_post_meta( ..., true ) behaviour).', 'acrossai-abilities-manager' ),
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
						'value'      => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) ),
						'meta_value' => array(
							'type'        => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ),
							'description' => __( 'Alias for "value" (matches WordPress core naming). If both are provided, "value" wins.', 'acrossai-abilities-manager' ),
						),
						'unique'     => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
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
						'meta_id' => array( 'type' => array( 'integer', 'boolean' ) ),
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
						'destructive' => false,
						'idempotent'  => false,
					),
					'input_flags'  => Slash_Input::meta_flags(),
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
				'message' => sprintf(
					/* translators: %d: post id */
					__( 'Post #%d not found.', 'acrossai-abilities-manager' ),
					$post_id
				),
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

		$value  = array_key_exists( 'value', $input ) ? $input['value'] : ( $input['meta_value'] ?? '' );
		$unique = ! empty( $input['unique'] );

		$meta_id = add_post_meta( $post_id, $key, Slash_Input::slash( $value, $input ), $unique );

		return array(
			'success' => true,
			'warnings' => $warnings,
			'meta_id' => false === $meta_id ? false : (int) $meta_id,
			'message' => false === $meta_id
				? sprintf(
					/* translators: 1: meta key, 2: post id */
					__( 'Append refused — a row for "%1$s" already exists on post #%2$d and unique:true was set.', 'acrossai-abilities-manager' ),
					$key,
					$post_id
				)
				: sprintf(
					/* translators: 1: meta key, 2: post id */
					__( 'Appended meta row for "%1$s" on post #%2$d.', 'acrossai-abilities-manager' ),
					$key,
					$post_id
				),
		);
	}
}
