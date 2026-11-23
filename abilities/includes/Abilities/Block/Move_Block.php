<?php
/**
 * Feature 066 — atomically move a block within a post.
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
 * Move a block from a source path to a destination (to_parent_path + index).
 * Refuses to move a block into its own subtree.
 */
class Move_Block extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/move-block',
			'args' => array(
				'label'               => __( 'Move Block', 'acrossai-abilities-manager' ),
				'description'         => __( 'Atomically move a Gutenberg block from a source path to a destination (to_parent_path + to_index). Refuses to move a block into its own subtree.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'from_path'      => array(
							'type'     => 'array',
							'minItems' => 1,
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'to_parent_path' => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'to_index'       => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					'required'             => array( 'post_id', 'from_path', 'to_parent_path', 'to_index' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'block'         => array( 'type' => 'object' ),
						'previous_path' => array( 'type' => 'array' ),
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
		$post_id   = absint( $input['post_id'] ?? 0 );
		$from_path = self::sanitize_path( $input['from_path'] ?? array() );
		$to_parent = self::sanitize_path( $input['to_parent_path'] ?? array(), true );
		$to_index  = (int) ( $input['to_index'] ?? 0 );

		if ( array() === $from_path ) {
			return self::fail( $post_id, 'invalid_path', __( 'A non-empty from_path is required.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return self::fail( $post_id, (string) $blocks->get_error_code(), (string) $blocks->get_error_message() );
		}

		$result = Block_Tree::move( $blocks, $from_path, $to_parent, $to_index );
		if ( is_wp_error( $result ) ) {
			return self::fail( $post_id, (string) $result->get_error_code(), (string) $result->get_error_message() );
		}

		$saved = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => serialize_blocks( $blocks ),
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return self::fail( $post_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		// Compute the new path for the moved block. Move() adjusted to_index
		// automatically for same-parent moves; the caller-facing "actual"
		// position may match to_index or the appended position.
		$children_count = self::children_count_at( $blocks, $to_parent );
		$actual_index   = min( $to_index, max( 0, $children_count - 1 ) );
		$new_path       = array_merge( $to_parent, array( $actual_index ) );
		$moved_block    = Block_Tree::get_at_path( $blocks, $new_path );
		if ( is_array( $moved_block ) ) {
			$moved_block['path'] = $new_path;
		}

		return array(
			'success'       => true,
			'post_id'       => $post_id,
			'block'         => $moved_block,
			'previous_path' => $from_path,
			/* translators: %d: post ID */
			'message'       => sprintf( __( 'Moved block on post #%d.', 'acrossai-abilities-manager' ), $post_id ),
		);
	}

	/**
	 * Coerce a raw path input to int[]. When $allow_empty is true, an empty
	 * array is a valid return value (root).
	 *
	 * @param mixed $raw
	 * @param bool  $allow_empty
	 * @return int[]
	 */
	private static function sanitize_path( $raw, bool $allow_empty = false ): array {
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
				return $allow_empty ? array() : array();
			}
		}
		return $out;
	}

	/**
	 * Children count under $parent_path (root when empty).
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
