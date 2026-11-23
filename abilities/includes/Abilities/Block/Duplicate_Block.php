<?php
/**
 * Feature 066 — deep-clone a block at a canonical path.
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
 * Deep-clone the block at the given path (PHP arrays copy by value, so the
 * clone is fully independent) and insert it as the next sibling.
 */
class Duplicate_Block extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/duplicate-block',
			'args' => array(
				'label'               => __( 'Duplicate Block', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a deep clone of the Gutenberg block at the given path and insert it as the next sibling.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'path'    => array(
							'type'     => 'array',
							'minItems' => 1,
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
					),
					'required'             => array( 'post_id', 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
						'block'   => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
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
		$path    = self::sanitize_path( $input['path'] ?? array() );
		if ( array() === $path ) {
			return self::fail( $post_id, 'invalid_path', __( 'A non-empty path is required.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return self::fail( $post_id, (string) $blocks->get_error_code(), (string) $blocks->get_error_message() );
		}

		$source = Block_Tree::get_at_path( $blocks, $path );
		if ( null === $source ) {
			return self::fail( $post_id, 'invalid_path', __( 'Path does not resolve.', 'acrossai-abilities-manager' ) );
		}

		// PHP arrays are value types — this assignment produces a deep copy.
		$clone = $source;
		unset( $clone['path'] );

		$parent_path  = $path;
		$source_index = (int) array_pop( $parent_path );
		$insert_index = $source_index + 1;

		if ( ! Block_Tree::insert_at_path( $blocks, $parent_path, $insert_index, $clone ) ) {
			return self::fail( $post_id, 'invalid_path', __( 'Failed to insert clone.', 'acrossai-abilities-manager' ) );
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

		$new_path      = array_merge( $parent_path, array( $insert_index ) );
		$cloned_block  = Block_Tree::get_at_path( $blocks, $new_path );
		if ( is_array( $cloned_block ) ) {
			$cloned_block['path'] = $new_path;
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
			'block'   => $cloned_block,
			/* translators: %d: post ID */
			'message' => sprintf( __( 'Duplicated block on post #%d.', 'acrossai-abilities-manager' ), $post_id ),
		);
	}

	/**
	 * Coerce a raw path input to int[].
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
