<?php
/**
 * Feature 071 — insert a reusable-block reference into a post at a given path.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Tree;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Insert a core/block { ref: <id> } reference into a target post at
 * parent_path + sibling index. Validates the reusable block exists.
 */
class Insert_Reusable_Block_Into_Post extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/insert-reusable-block-into-post',
			'args' => array(
				'label'               => __( 'Insert Reusable Block Into Post', 'acrossai-abilities-manager' ),
				'description'         => __( 'Insert a core/block reference to a reusable block into a target post at parent_path and sibling index. Does not duplicate content — inserts a reference. Validates the reusable block exists.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'           => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'reusable_block_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'parent_path'       => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'index'             => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					'required'             => array( 'post_id', 'reusable_block_id', 'parent_path', 'index' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
						'path'    => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'patterns',
						'sub_group_label' => __( 'Patterns', 'acrossai-abilities-manager' ),
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
		$post_id           = absint( $input['post_id'] ?? 0 );
		$reusable_block_id = absint( $input['reusable_block_id'] ?? 0 );
		$parent_path       = self::sanitize_path( $input['parent_path'] ?? array() );
		$index             = (int) ( $input['index'] ?? 0 );

		$reusable_post = $reusable_block_id > 0 ? get_post( $reusable_block_id ) : null;
		if ( ! $reusable_post instanceof \WP_Post || 'wp_block' !== $reusable_post->post_type ) {
			return $this->failure( $post_id, __( 'reusable_block_id does not resolve to a wp_block post.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		$reference_block = array(
			'blockName'    => 'core/block',
			'attrs'        => array( 'ref' => (int) $reusable_block_id ),
			'innerHTML'    => '',
			'innerBlocks'  => array(),
			'innerContent' => array(),
		);

		if ( ! Block_Tree::insert_at_path( $blocks, $parent_path, $index, $reference_block ) ) {
			return $this->failure( $post_id, __( 'parent_path does not resolve.', 'acrossai-abilities-manager' ) );
		}

		$saved = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => serialize_blocks( $blocks ),
			),
			true
		);

		if ( is_wp_error( $saved ) ) {
			return $this->failure( $post_id, (string) $saved->get_error_message() );
		}

		$children = self::children_count_at( $blocks, $parent_path );
		$actual   = min( $index, max( 0, $children - 1 ) );
		$new_path = array_merge( $parent_path, array( $actual ) );

		return array(
			'success' => true,
			'post_id' => $post_id,
			'path'    => $new_path,
			/* translators: 1: reusable block ID, 2: target post ID */
			'message' => sprintf( __( 'Inserted reference to reusable block #%1$d into post #%2$d.', 'acrossai-abilities-manager' ), $reusable_block_id, $post_id ),
		);
	}

	/**
	 * Coerce a path input to an int[].
	 *
	 * @param mixed $raw Raw input.
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
	 * Count children under the block at $parent_path (root when empty).
	 *
	 * @param array<int,array<string,mixed>> $blocks      Parsed blocks.
	 * @param int[]                          $parent_path Parent path.
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
	 * Consistent failure envelope.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( int $post_id, string $message ): array {
		return array(
			'success' => false,
			'post_id' => $post_id,
			'path'    => array(),
			'message' => $message,
		);
	}
}
