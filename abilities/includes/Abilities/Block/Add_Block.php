<?php
/**
 * Feature 066 — insert a new block into a post at a canonical path.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.0.24
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Tree;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Insert a block into a post's tree at parent_path + index. Appends when the
 * requested index is at or past the current sibling count.
 */
class Add_Block extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/add-block',
			'args' => array(
				'label'               => __( 'Add Block', 'acrossai-abilities-manager' ),
				'description'         => __( 'Insert a new Gutenberg block into a post at the given parent_path and sibling index. If index >= current sibling count, the block is appended.', 'acrossai-abilities-manager' ),
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
						'parent_path' => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'index'       => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'block'       => array(
							'type'       => 'object',
							'properties' => array(
								'name'        => array( 'type' => 'string' ),
								'attrs'       => array( 'type' => 'object' ),
								'innerHTML'   => array( 'type' => 'string' ),
								'innerBlocks' => array( 'type' => 'array' ),
							),
							'required'   => array( 'name' ),
						),
						'return_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, the response includes the inserted block\'s innerHTML, innerContent, and innerBlocks. Default false: those fields are stripped and content_bytes reports the saved innerHTML size, so a small insertion does not echo the whole block payload back through the tunnel. Callers who need to inspect the resolved block should pass true.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'post_id', 'parent_path', 'index', 'block' ),
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
		$post_id      = absint( $input['post_id'] ?? 0 );
		$parent_path  = self::sanitize_path( $input['parent_path'] ?? array() );
		$index        = (int) ( $input['index'] ?? 0 );
		$block_input  = is_array( $input['block'] ?? null ) ? $input['block'] : array();
		$block_name   = isset( $block_input['name'] ) ? (string) $block_input['name'] : '';

		if ( ! Block_Tree::validate_block_name( $block_name ) ) {
			return self::fail( $post_id, 'invalid_block_name', __( 'A valid block name (e.g. "core/paragraph") is required.', 'acrossai-abilities-manager' ) );
		}

		$attrs = isset( $block_input['attrs'] ) && is_array( $block_input['attrs'] ) ? $block_input['attrs'] : array();
		$attr_check = Block_Tree::validate_attributes_against_schema( $block_name, $attrs );
		if ( is_wp_error( $attr_check ) ) {
			return self::fail( $post_id, (string) $attr_check->get_error_code(), (string) $attr_check->get_error_message() );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return self::fail( $post_id, (string) $blocks->get_error_code(), (string) $blocks->get_error_message() );
		}

		$new_block = array(
			'blockName'   => $block_name,
			'attrs'       => $attrs,
			'innerHTML'   => isset( $block_input['innerHTML'] ) ? (string) $block_input['innerHTML'] : '',
			'innerBlocks' => isset( $block_input['innerBlocks'] ) && is_array( $block_input['innerBlocks'] ) ? $block_input['innerBlocks'] : array(),
		);

		if ( ! Block_Tree::insert_at_path( $blocks, $parent_path, $index, $new_block ) ) {
			return self::fail( $post_id, 'invalid_path', __( 'Parent path does not resolve.', 'acrossai-abilities-manager' ) );
		}

		$saved = self::persist( $post_id, $blocks );
		if ( is_wp_error( $saved ) ) {
			return self::fail( $post_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		// Compute the new block's actual path after insertion (index may have
		// been clamped to the append position).
		$parent_children_count = self::children_count_at( $blocks, $parent_path );
		$actual_index          = min( $index, max( 0, $parent_children_count - 1 ) );
		$new_path              = array_merge( $parent_path, array( $actual_index ) );
		$inserted              = Block_Tree::get_at_path( $blocks, $new_path );
		if ( is_array( $inserted ) ) {
			$inserted['path'] = $new_path;
		}

		$content_bytes  = is_array( $inserted ) ? strlen( (string) ( $inserted['innerHTML'] ?? '' ) ) : 0;
		$return_content = ! empty( $input['return_content'] );
		if ( ! $return_content && is_array( $inserted ) ) {
			unset( $inserted['innerHTML'], $inserted['innerContent'], $inserted['innerBlocks'] );
		}

		return array(
			'success'       => true,
			'post_id'       => $post_id,
			'block'         => $inserted,
			'content_bytes' => $content_bytes,
			/* translators: 1: block name, 2: post ID */
			'message'       => sprintf( __( 'Inserted %1$s into post #%2$d.', 'acrossai-abilities-manager' ), $block_name, $post_id ),
		);
	}

	/**
	 * Coerce the parent_path input to an int[].
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function sanitize_path( $raw ): array {
		if ( ! is_array( $raw ) ) {
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

	/**
	 * Save the mutated tree via wp_update_post().
	 *
	 * @param int                              $post_id
	 * @param array<int, array<string, mixed>> $blocks
	 * @return int|\WP_Error
	 */
	private static function persist( int $post_id, array $blocks ) {
		return wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => serialize_blocks( $blocks ),
			),
			true
		);
	}

	/**
	 * Count children under the block at $parent_path (root when empty).
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param int[]                            $parent_path
	 * @return int
	 */
	private static function children_count_at( array $blocks, array $parent_path ): int {
		if ( array() === $parent_path ) {
			return count( $blocks );
		}
		$parent = Block_Tree::get_at_path( $blocks, $parent_path );
		if ( ! is_array( $parent ) || ! isset( $parent['innerBlocks'] ) || ! is_array( $parent['innerBlocks'] ) ) {
			return 0;
		}
		return count( $parent['innerBlocks'] );
	}

	/**
	 * Build a failure envelope.
	 *
	 * @param int    $post_id
	 * @param string $code
	 * @param string $message
	 * @return array<string, mixed>
	 */
	private static function fail( int $post_id, string $code, string $message ): array {
		return array(
			'success'    => false,
			'post_id'    => $post_id,
			'message'    => $message,
			'error_code' => $code,
		);
	}
}
