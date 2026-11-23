<?php
/**
 * Feature 072 — atomic batch of primitive mutations against a post's block tree.
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
 * Apply an ordered list of insert/remove/move/update/duplicate operations to a
 * post's block tree. Atomic — no partial writes on failure.
 */
class Mutate_Block_Tree extends Ability_Definition {

	private const ALLOWED_OPS = array( 'insert', 'remove', 'move', 'update', 'duplicate' );

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/mutate-block-tree',
			'args' => array(
				'label'               => __( 'Mutate Block Tree', 'acrossai-abilities-manager' ),
				'description'         => __( 'Apply an ordered list of primitive mutations (insert, remove, move, update, duplicate) to a post\'s block tree in a single atomic operation. Reverts on any failure.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
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
						'operations' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'             => array( 'post_id', 'operations' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
						'applied' => array( 'type' => 'integer' ),
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
		$post_id    = absint( $input['post_id'] ?? 0 );
		$operations = is_array( $input['operations'] ?? null ) ? $input['operations'] : array();

		if ( array() === $operations ) {
			return $this->failure( $post_id, __( 'operations must be a non-empty array.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		foreach ( $operations as $i => $op ) {
			if ( ! is_array( $op ) ) {
				return $this->failure( $post_id, sprintf( 'Operation #%d is not an object.', (int) $i ) );
			}
			$type = (string) ( $op['op'] ?? '' );
			if ( ! in_array( $type, self::ALLOWED_OPS, true ) ) {
				return $this->failure( $post_id, sprintf( 'Operation #%d has invalid op "%s".', (int) $i, $type ) );
			}

			$applied = $this->apply_op( $blocks, $type, $op );
			if ( ! $applied ) {
				return $this->failure( $post_id, sprintf( 'Operation #%d (%s) failed.', (int) $i, $type ) );
			}
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

		return array(
			'success' => true,
			'post_id' => $post_id,
			'applied' => count( $operations ),
			/* translators: 1: operation count, 2: post ID */
			'message' => sprintf( __( 'Applied %1$d operation(s) to post #%2$d.', 'acrossai-abilities-manager' ), count( $operations ), $post_id ),
		);
	}

	/**
	 * Apply a single primitive op to the working tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Working tree (by ref).
	 * @param string                         $type   Op name.
	 * @param array<string,mixed>            $op     Op payload.
	 * @return bool
	 */
	private function apply_op( array &$blocks, string $type, array $op ): bool {
		switch ( $type ) {
			case 'insert':
				$parent = self::sanitize_path( $op['parent_path'] ?? array() );
				$index  = (int) ( $op['index'] ?? 0 );
				$block  = self::coerce_block( $op['block'] ?? array() );
				return null !== $block && Block_Tree::insert_at_path( $blocks, $parent, $index, $block );
			case 'remove':
				return null !== Block_Tree::remove_at_path( $blocks, self::sanitize_path( $op['path'] ?? array() ) );
			case 'move':
				$from = self::sanitize_path( $op['from'] ?? array() );
				$to_p = self::sanitize_path( $op['to_parent'] ?? array() );
				$to_i = (int) ( $op['to_index'] ?? 0 );
				return (bool) Block_Tree::move( $blocks, $from, $to_p, $to_i );
			case 'update':
				$path  = self::sanitize_path( $op['path'] ?? array() );
				$block = self::coerce_block( $op['block'] ?? array() );
				return null !== $block && Block_Tree::replace_at_path( $blocks, $path, $block );
			case 'duplicate':
				$path = self::sanitize_path( $op['path'] ?? array() );
				$src  = Block_Tree::get_at_path( $blocks, $path );
				if ( ! is_array( $src ) || array() === $path ) {
					return false;
				}
				$parent = array_slice( $path, 0, -1 );
				$index  = (int) end( $path ) + 1;
				return Block_Tree::insert_at_path( $blocks, $parent, $index, $src );
			default:
				return false;
		}
	}

	/**
	 * Coerce a block-payload input into the parse_blocks shape.
	 *
	 * @param mixed $raw Raw block object.
	 * @return array<string,mixed>|null
	 */
	private static function coerce_block( $raw ): ?array {
		if ( ! is_array( $raw ) || empty( $raw['name'] ) ) {
			return null;
		}
		$name = (string) $raw['name'];
		if ( ! Block_Tree::validate_block_name( $name ) ) {
			return null;
		}
		return array(
			'blockName'    => $name,
			'attrs'        => is_array( $raw['attrs'] ?? null ) ? $raw['attrs'] : array(),
			'innerHTML'    => isset( $raw['innerHTML'] ) ? (string) $raw['innerHTML'] : '',
			'innerBlocks'  => is_array( $raw['innerBlocks'] ?? null ) ? $raw['innerBlocks'] : array(),
			'innerContent' => is_array( $raw['innerContent'] ?? null ) ? $raw['innerContent'] : array(),
		);
	}

	/**
	 * Coerce raw path input into int[].
	 *
	 * @param mixed $raw Raw path.
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
			'applied' => 0,
			'message' => $message,
		);
	}
}
