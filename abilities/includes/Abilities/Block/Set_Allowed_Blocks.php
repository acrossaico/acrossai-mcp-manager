<?php
/**
 * Feature 073 — set allowedBlocks on a container block.
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
 * Set `allowedBlocks` on a container block. Empty array removes the constraint.
 */
class Set_Allowed_Blocks extends Ability_Definition {

	private const CONTAINERS = array(
		'core/group',
		'core/columns',
		'core/column',
		'core/cover',
		'core/query',
		'core/post-template',
		'core/list',
		'core/buttons',
		'core/social-links',
		'core/navigation',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/set-allowed-blocks',
			'args' => array(
				'label'               => __( 'Set Allowed Blocks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Set the `allowedBlocks` attribute on a container block. Empty array removes the restriction. Rejects non-container targets.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'path'           => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer', 'minimum' => 0 ),
						),
						'allowed_blocks' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required'             => array( 'post_id', 'path', 'allowed_blocks' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
						'path'    => array( 'type' => 'array' ),
						'before'  => array( 'type' => 'array' ),
						'after'   => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'mutation',
						'sub_group_label' => __( 'Mutation', 'acrossai-abilities-manager' ),
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
		$allowed = is_array( $input['allowed_blocks'] ?? null ) ? array_map( 'strval', $input['allowed_blocks'] ) : array();

		if ( array() === $path ) {
			return $this->failure( $post_id, __( 'path must be a non-empty integer array.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		$target = Block_Tree::get_at_path( $blocks, $path );
		if ( ! is_array( $target ) ) {
			return $this->failure( $post_id, __( 'path does not resolve.', 'acrossai-abilities-manager' ) );
		}

		$name = (string) ( $target['blockName'] ?? '' );
		if ( ! in_array( $name, self::CONTAINERS, true ) && ! apply_filters( 'acrossai_block_is_container', false, $name ) ) {
			return $this->failure( $post_id, __( 'Target block is not a container (does not accept inner blocks).', 'acrossai-abilities-manager' ) );
		}

		$before = is_array( $target['attrs']['allowedBlocks'] ?? null ) ? $target['attrs']['allowedBlocks'] : array();

		if ( array() === $allowed ) {
			unset( $target['attrs']['allowedBlocks'] );
		} else {
			$target['attrs']['allowedBlocks'] = array_values( $allowed );
		}

		Block_Tree::replace_at_path( $blocks, $path, $target );

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

		return array(
			'success' => true,
			'post_id' => $post_id,
			'path'    => $path,
			'before'  => $before,
			'after'   => $allowed,
			'message' => __( 'allowedBlocks updated.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Coerce raw path to int[].
	 *
	 * @param mixed $raw Raw path input.
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
	 * Failure envelope.
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
			'before'  => array(),
			'after'   => array(),
			'message' => $message,
		);
	}
}
