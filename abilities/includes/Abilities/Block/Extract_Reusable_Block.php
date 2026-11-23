<?php
/**
 * Feature 071 — extract a block subtree from a post into a new reusable block.
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
 * Extract a subtree at a given path into a new wp_block, then replace the
 * source location with a core/block reference. Atomic — reverts source-post
 * write if wp_block creation fails.
 */
class Extract_Reusable_Block extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/extract-reusable-block',
			'args' => array(
				'label'               => __( 'Extract Reusable Block', 'acrossai-abilities-manager' ),
				'description'         => __( 'Extract a block subtree at the given canonical path from a source post into a new reusable block (wp_block). Replaces the source location with a core/block reference. Atomic — rolls back the source-post rewrite if the wp_block creation fails.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
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
						'path'    => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'title'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'path', 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'           => array( 'type' => 'boolean' ),
						'post_id'           => array( 'type' => 'integer' ),
						'reusable_block_id' => array( 'type' => 'integer' ),
						'message'           => array( 'type' => 'string' ),
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
						'destructive' => true,
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
		$path    = self::sanitize_path( $input['path'] ?? array() );
		$title   = sanitize_text_field( (string) ( $input['title'] ?? '' ) );

		if ( '' === $title ) {
			return $this->failure( $post_id, __( 'title is required.', 'acrossai-abilities-manager' ) );
		}
		if ( array() === $path ) {
			return $this->failure( $post_id, __( 'path must be a non-empty array of integers.', 'acrossai-abilities-manager' ) );
		}

		$source = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $source instanceof \WP_Post ) {
			return $this->failure( $post_id, __( 'Source post not found.', 'acrossai-abilities-manager' ) );
		}
		$original_content = (string) $source->post_content;

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		$subtree = Block_Tree::get_at_path( $blocks, $path );
		if ( ! is_array( $subtree ) ) {
			return $this->failure( $post_id, __( 'path does not resolve to a block.', 'acrossai-abilities-manager' ) );
		}

		$reusable_id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => serialize_blocks( array( $subtree ) ),
			),
			true
		);

		if ( is_wp_error( $reusable_id ) ) {
			return $this->failure( $post_id, (string) $reusable_id->get_error_message() );
		}

		$reference_block = array(
			'blockName'   => 'core/block',
			'attrs'       => array( 'ref' => (int) $reusable_id ),
			'innerHTML'   => '',
			'innerBlocks' => array(),
			'innerContent' => array(),
		);

		if ( ! Block_Tree::replace_at_path( $blocks, $path, $reference_block ) ) {
			wp_delete_post( (int) $reusable_id, true );
			return $this->failure( $post_id, __( 'Failed to replace source subtree with reference.', 'acrossai-abilities-manager' ) );
		}

		$saved = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => serialize_blocks( $blocks ),
			),
			true
		);

		if ( is_wp_error( $saved ) ) {
			wp_delete_post( (int) $reusable_id, true );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $original_content,
				),
				true
			);
			return $this->failure( $post_id, (string) $saved->get_error_message() );
		}

		return array(
			'success'           => true,
			'post_id'           => $post_id,
			'reusable_block_id' => (int) $reusable_id,
			/* translators: 1: reusable block ID, 2: source post ID */
			'message'           => sprintf( __( 'Extracted subtree into reusable block #%1$d and updated source post #%2$d.', 'acrossai-abilities-manager' ), (int) $reusable_id, $post_id ),
		);
	}

	/**
	 * Coerce the path input to an int[].
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
	 * Consistent failure envelope.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( int $post_id, string $message ): array {
		return array(
			'success'           => false,
			'post_id'           => $post_id,
			'reusable_block_id' => 0,
			'message'           => $message,
		);
	}
}
