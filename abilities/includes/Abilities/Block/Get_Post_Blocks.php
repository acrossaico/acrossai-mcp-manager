<?php
/**
 * Feature 066 — return a post's parsed block tree with canonical paths.
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
 * Read a post's block tree via parse_blocks() and annotate every node with a
 * canonical integer-array `path`. Read-only, idempotent, does not touch the
 * database beyond a single get_post() lookup.
 */
class Get_Post_Blocks extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/get-post-blocks',
			'args' => array(
				'label'               => __( 'Get Post Blocks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return a post\'s parsed Gutenberg block tree with each block annotated with its canonical integer-array path. Pass "path" to scope to a subtree (avoids fetching the whole page just to read one block), "depth" to bound how far below that subtree to descend (-1 unlimited, 0 subtree root only), and "include_html: false" to strip every node\'s innerHTML and innerContent when you only need structure. Paths use the same raw parse_blocks() index scheme as blocks/add-block / blocks/update-post-block / blocks/remove-block.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'path'         => array(
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'description' => __( 'Start at this subtree instead of the top level. Empty (default) returns the full tree. Uses the same raw parse_blocks() index scheme as add-block / update-post-block / remove-block.', 'acrossai-abilities-manager' ),
						),
						'depth'        => array(
							'type'        => 'integer',
							'minimum'     => -1,
							'default'     => -1,
							'description' => __( 'Levels below the subtree root to descend. -1 (default) = unlimited, 0 = subtree root only, N = N levels below.', 'acrossai-abilities-manager' ),
						),
						'include_html' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'When false, every returned block has its innerHTML and innerContent removed. Default true preserves backwards-compat.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'post_id'      => array( 'type' => 'integer' ),
						'blocks'       => array( 'type' => 'array' ),
						'total'        => array( 'type' => 'integer' ),
						'path'         => array( 'type' => 'array' ),
						'include_html' => array( 'type' => 'boolean' ),
						'message'      => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
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
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Feature 095 — hint that when the caller only needs to locate a block,
	 * the outline ability returns kilobytes instead of the full parsed tree
	 * (which includes every block's innerHTML).
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'blocks/outline-post-blocks',
				'reason' => __( 'If you only need block paths, types, and short previews, this returns kilobytes even for pages that get-post-blocks would return as hundreds — same path convention, so the returned paths are drop-in usable with add-block / update-post-block / remove-block.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~28K tokens on a 97 KB page', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$post_id      = absint( $input['post_id'] ?? 0 );
		$start_path   = self::sanitize_path( $input['path'] ?? array() );
		$depth        = isset( $input['depth'] ) ? (int) $input['depth'] : -1;
		$include_html = ! isset( $input['include_html'] ) || (bool) $input['include_html'];

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'read' );
		if ( is_wp_error( $blocks ) ) {
			return array(
				'success'    => false,
				'post_id'    => $post_id,
				'message'    => $blocks->get_error_message(),
				'error_code' => $blocks->get_error_code(),
			);
		}

		// Resolve the start-at subtree when path is non-empty.
		if ( array() !== $start_path ) {
			$subtree = Block_Tree::get_at_path( $blocks, $start_path );
			if ( null === $subtree ) {
				return array(
					'success'    => false,
					'post_id'    => $post_id,
					'path'       => $start_path,
					'message'    => self::format_path_error( $blocks, $start_path ),
					'error_code' => 'invalid_path',
				);
			}
			// Wrap the single subtree back in a list so downstream annotate /
			// walk / strip helpers keep operating on a root-list shape.
			$scoped_root  = array( $subtree );
			$path_prefix  = self::parent_path( $start_path );
			$leaf_index   = (int) end( $start_path );
			$scoped_root  = self::normalise_scoped_root( $scoped_root, $leaf_index );
		} else {
			$scoped_root = $blocks;
			$path_prefix = array();
		}

		// Depth truncation is applied on a copy so path annotation reads the
		// truncated shape.
		$scoped_root = self::truncate_depth( $scoped_root, $depth );

		$annotated = Block_Tree::annotate_with_paths( $scoped_root, $path_prefix );

		if ( ! $include_html ) {
			$annotated = self::strip_html( $annotated );
		}

		$total = 0;
		Block_Tree::walk_tree(
			$scoped_root,
			static function () use ( &$total ): void {
				++$total;
			},
			$path_prefix
		);

		return array(
			'success'      => true,
			'post_id'      => $post_id,
			'blocks'       => $annotated,
			'total'        => $total,
			'path'         => $start_path,
			'include_html' => $include_html,
			/* translators: 1: total block count, 2: post ID */
			'message'      => sprintf( __( 'Returned %1$d blocks for post #%2$d.', 'acrossai-abilities-manager' ), $total, $post_id ),
		);
	}

	/**
	 * Coerce a raw path input to int[]. Matches Add_Block / Remove_Block /
	 * Outline_Post_Blocks conventions — rejects any non-integer element by
	 * returning an empty array.
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
	 * Path minus its last element. `parent_path([3, 1])` → `[3]`;
	 * `parent_path([3])` → `[]`.
	 *
	 * @param int[] $path
	 * @return int[]
	 */
	private static function parent_path( array $path ): array {
		if ( array() === $path ) {
			return array();
		}
		array_pop( $path );
		return $path;
	}

	/**
	 * `annotate_with_paths()` re-indexes the outer array to 0..N-1. When a
	 * scoped subtree is wrapped in `array( $subtree )`, that outer index is 0
	 * — but the caller's real path uses the leaf index (e.g. 1 in path [3,1]).
	 * We fix this by pre-inflating the outer array so `annotate_with_paths`'s
	 * numeric key matches the caller's leaf index.
	 *
	 * @param array<int,mixed> $scoped_root Single-element scoped subtree.
	 * @param int              $leaf_index  The path's last integer.
	 * @return array<int,mixed>
	 */
	private static function normalise_scoped_root( array $scoped_root, int $leaf_index ): array {
		if ( 0 === $leaf_index ) {
			return $scoped_root;
		}
		$out               = array_fill( 0, $leaf_index, null );
		$out[ $leaf_index ] = $scoped_root[0];
		return $out;
	}

	/**
	 * Truncate every subtree past $depth levels below its own root. -1 means
	 * unlimited (pass-through). 0 clears every innerBlocks entry on the root
	 * list. N leaves the top N-1 levels of nested innerBlocks intact.
	 *
	 * @param array<int, array<string, mixed>|null> $blocks Root list.
	 * @param int                                   $depth  -1 unlimited, 0 root only, N below.
	 * @return array<int, array<string, mixed>|null>
	 */
	private static function truncate_depth( array $blocks, int $depth ): array {
		if ( -1 === $depth ) {
			return $blocks;
		}
		return self::truncate_recursive( $blocks, $depth );
	}

	/**
	 * @param array<int, array<string, mixed>|null> $blocks
	 * @param int                                   $remaining Levels of nesting still allowed BELOW the current node.
	 * @return array<int, array<string, mixed>|null>
	 */
	private static function truncate_recursive( array $blocks, int $remaining ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$out[] = $block;
				continue;
			}
			if ( $remaining <= 0 ) {
				$block['innerBlocks'] = array();
			} elseif ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::truncate_recursive( array_values( $block['innerBlocks'] ), $remaining - 1 );
			}
			$out[] = $block;
		}
		return $out;
	}

	/**
	 * Walk every node and strip `innerHTML` + `innerContent`. Preserves every
	 * other field (`blockName`, `attrs`, `innerBlocks`, `path`, etc.).
	 *
	 * @param array<int, array<string, mixed>|null> $blocks
	 * @return array<int, array<string, mixed>|null>
	 */
	private static function strip_html( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$out[] = $block;
				continue;
			}
			unset( $block['innerHTML'], $block['innerContent'] );
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::strip_html( array_values( $block['innerBlocks'] ) );
			}
			$out[] = $block;
		}
		return $out;
	}

	/**
	 * Human-readable "path does not resolve" error naming which depth failed
	 * and how many blocks exist at that level. Mirrors the shape
	 * `Outline_Post_Blocks::format_path_error()` uses.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param int[]                            $path
	 * @return string
	 */
	private static function format_path_error( array $blocks, array $path ): string {
		$cursor = $blocks;
		foreach ( $path as $depth => $index ) {
			$available = count( $cursor );
			if ( ! isset( $cursor[ $index ] ) || ! is_array( $cursor[ $index ] ) ) {
				return sprintf(
					/* translators: 1: depth index, 2: requested index, 3: available count */
					__( 'Path does not resolve at depth %1$d: requested index %2$d but only %3$d block(s) exist at that level.', 'acrossai-abilities-manager' ),
					(int) $depth,
					(int) $index,
					(int) $available
				);
			}
			$cursor = isset( $cursor[ $index ]['innerBlocks'] ) && is_array( $cursor[ $index ]['innerBlocks'] )
				? array_values( $cursor[ $index ]['innerBlocks'] )
				: array();
		}
		return __( 'Path does not resolve.', 'acrossai-abilities-manager' );
	}
}
