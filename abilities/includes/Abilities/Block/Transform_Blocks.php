<?php
/**
 * Feature 072 — apply named structural transforms to blocks at given paths.
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
 * Apply named block transforms (paragraph-to-heading, heading-to-paragraph,
 * group-to-columns, columns-to-group).
 */
class Transform_Blocks extends Ability_Definition {

	private const TRANSFORMS = array(
		'paragraph-to-heading',
		'heading-to-paragraph',
		'group-to-columns',
		'columns-to-group',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/transform-blocks',
			'args' => array(
				'label'               => __( 'Transform Blocks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Convert one or more blocks at specified paths using a named transform: paragraph-to-heading, heading-to-paragraph, group-to-columns, columns-to-group. Atomic per-post.', 'acrossai-abilities-manager' ),
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
						'transforms' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'             => array( 'post_id', 'transforms' ),
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
		$transforms = is_array( $input['transforms'] ?? null ) ? $input['transforms'] : array();

		if ( array() === $transforms ) {
			return $this->failure( $post_id, __( 'transforms must be a non-empty array.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		foreach ( $transforms as $i => $t ) {
			if ( ! is_array( $t ) ) {
				return $this->failure( $post_id, sprintf( 'Transform #%d is not an object.', (int) $i ) );
			}
			$name = (string) ( $t['transform'] ?? '' );
			if ( ! in_array( $name, self::TRANSFORMS, true ) ) {
				return $this->failure( $post_id, sprintf( 'Transform #%d has invalid transform "%s".', (int) $i, $name ) );
			}
			$path = self::sanitize_path( $t['path'] ?? array() );
			$src  = Block_Tree::get_at_path( $blocks, $path );
			if ( ! is_array( $src ) ) {
				return $this->failure( $post_id, sprintf( 'Transform #%d: path does not resolve.', (int) $i ) );
			}
			$new = $this->apply_transform( $name, $src );
			if ( null === $new ) {
				return $this->failure( $post_id, sprintf( 'Transform #%d: source block is not compatible with "%s".', (int) $i, $name ) );
			}
			if ( ! Block_Tree::replace_at_path( $blocks, $path, $new ) ) {
				return $this->failure( $post_id, sprintf( 'Transform #%d: replace failed.', (int) $i ) );
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
			'applied' => count( $transforms ),
			/* translators: 1: transform count, 2: post ID */
			'message' => sprintf( __( 'Applied %1$d transform(s) to post #%2$d.', 'acrossai-abilities-manager' ), count( $transforms ), $post_id ),
		);
	}

	/**
	 * Apply one named transform to a source block; return the new block or null.
	 *
	 * @param string              $name Transform name.
	 * @param array<string,mixed> $src  Source block.
	 * @return array<string,mixed>|null
	 */
	private function apply_transform( string $name, array $src ): ?array {
		$block_name = (string) ( $src['blockName'] ?? '' );
		switch ( $name ) {
			case 'paragraph-to-heading':
				if ( 'core/paragraph' !== $block_name ) {
					return null;
				}
				return array(
					'blockName'    => 'core/heading',
					'attrs'        => array_merge( array( 'level' => 2 ), (array) ( $src['attrs'] ?? array() ) ),
					'innerHTML'    => (string) ( $src['innerHTML'] ?? '' ),
					'innerBlocks'  => array(),
					'innerContent' => array( (string) ( $src['innerHTML'] ?? '' ) ),
				);
			case 'heading-to-paragraph':
				if ( 'core/heading' !== $block_name ) {
					return null;
				}
				$attrs = (array) ( $src['attrs'] ?? array() );
				unset( $attrs['level'] );
				return array(
					'blockName'    => 'core/paragraph',
					'attrs'        => $attrs,
					'innerHTML'    => (string) ( $src['innerHTML'] ?? '' ),
					'innerBlocks'  => array(),
					'innerContent' => array( (string) ( $src['innerHTML'] ?? '' ) ),
				);
			case 'group-to-columns':
				if ( 'core/group' !== $block_name ) {
					return null;
				}
				$children = is_array( $src['innerBlocks'] ?? null ) ? $src['innerBlocks'] : array();
				$col_children = array();
				foreach ( $children as $c ) {
					$col_children[] = array(
						'blockName'    => 'core/column',
						'attrs'        => array(),
						'innerHTML'    => '',
						'innerBlocks'  => array( $c ),
						'innerContent' => array( null ),
					);
				}
				return array(
					'blockName'    => 'core/columns',
					'attrs'        => array(),
					'innerHTML'    => '',
					'innerBlocks'  => $col_children,
					'innerContent' => array_fill( 0, count( $col_children ), null ),
				);
			case 'columns-to-group':
				if ( 'core/columns' !== $block_name ) {
					return null;
				}
				$flat = array();
				foreach ( ( is_array( $src['innerBlocks'] ?? null ) ? $src['innerBlocks'] : array() ) as $column ) {
					if ( is_array( $column['innerBlocks'] ?? null ) ) {
						foreach ( $column['innerBlocks'] as $c ) {
							$flat[] = $c;
						}
					}
				}
				return array(
					'blockName'    => 'core/group',
					'attrs'        => array(),
					'innerHTML'    => '',
					'innerBlocks'  => $flat,
					'innerContent' => array_fill( 0, count( $flat ), null ),
				);
			default:
				return null;
		}
	}

	/**
	 * Coerce raw path to int[].
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
