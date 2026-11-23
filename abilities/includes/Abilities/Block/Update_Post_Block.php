<?php
/**
 * Feature 055 — surgically update a single block inside a post.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Tree;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Parse a post's block tree, find a single block, merge new `attributes`,
 * replace `innerHTML`, and save the post with the serialised block tree.
 *
 * Feature 066: adds an optional `path` input (int[]) so callers can target
 * blocks at any nesting depth. When `path` is absent the legacy addressing
 * modes (block_index or block_name+occurrence) continue to work byte-
 * identically to earlier releases.
 */
class Update_Post_Block extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/update-post-block',
			'args' => array(
				'label'               => __( 'Update Block', 'acrossai-abilities-manager' ),
				'description'         => __( 'Parse a post\'s block tree, find one block, merge the supplied attributes, replace innerHTML, and save the post. Targeting priority: (1) path — a canonical integer-array path targeting a block at any nesting depth; (2) block_index — 0-based top-level index; (3) block_name (+ optional occurrence).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'path'        => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'block_index' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'block_name'  => array( 'type' => 'string' ),
						'occurrence'  => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'attributes'  => array( 'type' => 'object' ),
						'inner_html'  => array( 'type' => 'string' ),
						'return_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, the response includes the saved block\'s innerHTML, innerContent, and innerBlocks. Default false: those fields are stripped and content_bytes reports the saved innerHTML size, so a small edit does not echo the whole block payload back through the tunnel. Callers who need to inspect the resolved block should pass true.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'block'         => array( 'type' => 'object' ),
						'content_bytes' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'post-blocks',
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
	/**
	 * Where a caller should look before running this.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'content/inspect-post-builder',
				'reason' => __( 'Run first on an existing post. A page built with Elementor or another page builder keeps its layout outside post_content, so this write can report success, change nothing a visitor sees, and be reverted the next time the page is saved in the builder.', 'acrossai-abilities-manager' ),
			),
		);
	}

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

		// Feature 055 hardening — per-post cap check + internal-CPT filter.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to edit this post.', 'acrossai-abilities-manager' ),
			);
		}
		$post_type_obj = get_post_type_object( (string) $post->post_type );
		if ( ! $post_type_obj instanceof \WP_Post_Type
			|| in_array( $post_type_obj->name, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true )
			|| ! ( (bool) $post_type_obj->public || (bool) $post_type_obj->show_ui || (bool) $post_type_obj->show_in_rest ) ) {
			return array(
				'success' => false,
				'message' => __( 'This post type is not editable through this ability.', 'acrossai-abilities-manager' ),
			);
		}

		$blocks = parse_blocks( (string) $post->post_content );
		if ( ! is_array( $blocks ) || array() === $blocks ) {
			return array(
				'success' => false,
				'message' => __( 'Post contains no parseable blocks.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 066 — nested-path branch runs first. When `path` is present
		// and non-empty, target the block at that path anywhere in the tree.
		// When absent, fall through to the legacy top-level branches below,
		// preserving byte-identical behaviour for existing consumers.
		$path = self::sanitize_path_input( $input['path'] ?? null );
		if ( array() !== $path ) {
			$target = Block_Tree::get_at_path( $blocks, $path );
			if ( ! is_array( $target ) ) {
				return array(
					'success' => false,
					'message' => __( 'Path does not resolve to a block.', 'acrossai-abilities-manager' ),
				);
			}
			if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
				$target['attrs'] = array_merge( (array) ( $target['attrs'] ?? array() ), $input['attributes'] );
			}
			if ( isset( $input['inner_html'] ) ) {
				$target['innerHTML']    = (string) $input['inner_html'];
				$target['innerContent'] = array( (string) $input['inner_html'] );
			}
			if ( ! Block_Tree::replace_at_path( $blocks, $path, $target ) ) {
				return array(
					'success' => false,
					'message' => __( 'Failed to update block at path.', 'acrossai-abilities-manager' ),
				);
			}
			$new_content = serialize_blocks( $blocks );
			$updated     = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return array(
					'success' => false,
					'message' => $updated->get_error_message(),
				);
			}
			$target['path']  = $path;
			$content_bytes   = strlen( (string) ( $target['innerHTML'] ?? '' ) );
			$return_content  = ! empty( $input['return_content'] );
			if ( ! $return_content ) {
				unset( $target['innerHTML'], $target['innerContent'], $target['innerBlocks'] );
			}
			return array(
				'success'       => true,
				'post_id'       => $post_id,
				'block'         => $target,
				'content_bytes' => $content_bytes,
				/* translators: 1: path string, 2: post ID */
				'message'       => sprintf( __( 'Updated block at path [%1$s] on post #%2$d.', 'acrossai-abilities-manager' ), implode( ',', $path ), $post_id ),
			);
		}

		$target_index = null;
		if ( isset( $input['block_index'] ) ) {
			$target_index = (int) $input['block_index'];
			if ( $target_index < 0 || ! isset( $blocks[ $target_index ] ) ) {
				return array(
					'success' => false,
					/* translators: %d: block index */
					'message' => sprintf( __( 'Block index %d out of range.', 'acrossai-abilities-manager' ), $target_index ),
				);
			}
		} else {
			// Feature 055 hardening — allow only Gutenberg block-name shape
			// (e.g. "core/paragraph"): namespace/name pairs of [A-Za-z0-9_-].
			$block_name = (string) ( $input['block_name'] ?? '' );
			$occurrence = max( 0, (int) ( $input['occurrence'] ?? 0 ) );
			if ( '' === $block_name || ! preg_match( '/^[A-Za-z0-9_-]+\/[A-Za-z0-9_-]+$/', $block_name ) ) {
				return array(
					'success' => false,
					'message' => __( 'One of block_index or a valid block_name (e.g. "core/paragraph") is required.', 'acrossai-abilities-manager' ),
				);
			}
			$hits = 0;
			foreach ( $blocks as $idx => $block ) {
				if ( ( $block['blockName'] ?? '' ) === $block_name ) {
					if ( $hits === $occurrence ) {
						$target_index = (int) $idx;
						break;
					}
					++$hits;
				}
			}
			if ( null === $target_index ) {
				return array(
					'success' => false,
					/* translators: 1: block name, 2: occurrence */
					'message' => sprintf( __( 'No top-level block "%1$s" at occurrence %2$d.', 'acrossai-abilities-manager' ), $block_name, $occurrence ),
				);
			}
		}

		if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
			$blocks[ $target_index ]['attrs'] = array_merge( (array) ( $blocks[ $target_index ]['attrs'] ?? array() ), $input['attributes'] );
		}
		if ( isset( $input['inner_html'] ) ) {
			$blocks[ $target_index ]['innerHTML']    = (string) $input['inner_html'];
			$blocks[ $target_index ]['innerContent'] = array( (string) $input['inner_html'] );
		}

		$new_content = serialize_blocks( $blocks );
		$updated     = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return array(
				'success' => false,
				'message' => $updated->get_error_message(),
			);
		}

		$updated_block   = $blocks[ $target_index ];
		$content_bytes   = strlen( (string) ( $updated_block['innerHTML'] ?? '' ) );
		$return_content  = ! empty( $input['return_content'] );
		if ( ! $return_content ) {
			unset( $updated_block['innerHTML'], $updated_block['innerContent'], $updated_block['innerBlocks'] );
		}

		return array(
			'success'       => true,
			'post_id'       => $post_id,
			'block'         => $updated_block,
			'content_bytes' => $content_bytes,
			/* translators: 1: index, 2: post ID */
			'message'       => sprintf( __( 'Updated block #%1$d on post #%2$d.', 'acrossai-abilities-manager' ), $target_index, $post_id ),
		);
	}

	/**
	 * Feature 066 — coerce a raw `path` input to int[] and return an empty
	 * array (which means "path input absent, use legacy branches") for any
	 * malformed or missing value. A valid non-empty path is one or more
	 * non-negative integers.
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function sanitize_path_input( $raw ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_int( $item ) && $item >= 0 ) {
				$out[] = $item;
			} elseif ( is_string( $item ) && ctype_digit( $item ) ) {
				$out[] = (int) $item;
			} else {
				return array();
			}
		}
		return $out;
	}
}
