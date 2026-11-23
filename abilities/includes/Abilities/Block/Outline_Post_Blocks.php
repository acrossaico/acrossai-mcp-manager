<?php
/**
 * Return a flat, cheap-to-consume index of a post's Gutenberg blocks —
 * paths, types, and short text previews without any block content.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.0.32
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Tree;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Cheap block index — read-only companion to blocks/get-post-blocks.
 *
 * Uses the same raw-index path scheme as Block_Tree so a path returned here
 * is directly usable with blocks/add-block, blocks/update-post-block, and
 * blocks/remove-block. Whitespace nodes (parse_blocks entries with
 * blockName === null) are excluded from the output but still consume index
 * positions during traversal — same convention Block_Tree already uses.
 *
 * Paths are positional; any write can re-serialize the post and shift raw
 * indices. Callers should re-outline after each write rather than caching
 * paths across edits. The response's post_modified_gmt lets callers detect
 * staleness by comparing against the modified stamp on a later read.
 */
class Outline_Post_Blocks extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/outline-post-blocks',
			'args' => array(
				'label'               => __( 'Outline Post Blocks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return a flat, depth-first index of a post\'s Gutenberg blocks — canonical path, block type, child count, byte size, and a short text preview — without any block content. Cheap way to locate a block before editing it via blocks/add-block, blocks/update-post-block, or blocks/remove-block; paths returned here are drop-in usable with those abilities. Paths are positional and valid as of the response\'s post_modified_gmt: re-run the outline after any write rather than caching paths across edits. The "contains" filter matches only within the extracted text preview (up to max_text characters), so raise max_text for deeper substring searches. depth counts from the post: 1 returns top-level blocks; 0 with no path returns nothing.', 'acrossai-abilities-manager' ),
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
						'path'           => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'depth'          => array(
							'type'    => 'integer',
							'minimum' => -1,
							'default' => -1,
						),
						'max_text'       => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 500,
							'default' => 80,
						),
						'block_names'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'contains'       => array( 'type' => 'string' ),
						'include_attrs'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'max_results'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 2000,
							'default' => 500,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'           => array( 'type' => 'boolean' ),
						'post_id'           => array( 'type' => 'integer' ),
						'post_modified_gmt' => array( 'type' => 'string' ),
						'total'             => array(
							'type'        => 'integer',
							'description' => __( 'How many blocks matched the filters, before max_results was applied.', 'acrossai-abilities-manager' ),
						),
						'returned'          => array(
							'type'        => 'integer',
							'description' => __( 'How many blocks are in this response. Lower than total when truncated is true.', 'acrossai-abilities-manager' ),
						),
						'truncated'         => array( 'type' => 'boolean' ),
						'blocks'            => array( 'type' => 'array' ),
						'message'           => array( 'type' => 'string' ),
						'error_code'        => array( 'type' => 'string' ),
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
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$post_id       = absint( $input['post_id'] ?? 0 );
		$start_path    = self::sanitize_path( $input['path'] ?? array() );
		$depth         = isset( $input['depth'] ) ? (int) $input['depth'] : -1;
		$max_text      = isset( $input['max_text'] ) ? max( 0, min( 500, (int) $input['max_text'] ) ) : 80;
		$block_names   = self::sanitize_block_names( $input['block_names'] ?? null );
		$contains      = isset( $input['contains'] ) ? (string) $input['contains'] : '';
		$include_attrs = ! empty( $input['include_attrs'] );
		$max_results   = isset( $input['max_results'] ) ? max( 1, min( 2000, (int) $input['max_results'] ) ) : 500;

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'read' );
		if ( is_wp_error( $blocks ) ) {
			return array(
				'success'    => false,
				'post_id'    => $post_id,
				'message'    => $blocks->get_error_message(),
				'error_code' => (string) $blocks->get_error_code(),
			);
		}

		$post              = get_post( $post_id );
		$post_modified_gmt = $post instanceof WP_Post ? (string) $post->post_modified_gmt : '';

		// Resolve the start-at subtree. Empty path means "root".
		if ( array() !== $start_path ) {
			$subtree = Block_Tree::get_at_path( $blocks, $start_path );
			if ( null === $subtree ) {
				return array(
					'success'    => false,
					'post_id'    => $post_id,
					'message'    => self::format_path_error( $blocks, $start_path ),
					'error_code' => 'invalid_path',
				);
			}
		}

		$outcome = self::build_outline(
			$blocks,
			$start_path,
			$depth,
			$max_text,
			$block_names,
			$contains,
			$include_attrs,
			$max_results
		);

		return array(
			'success'           => true,
			'post_id'           => $post_id,
			'post_modified_gmt' => $post_modified_gmt,
			'total'             => (int) $outcome['matched'],
			'returned'          => count( $outcome['blocks'] ),
			'truncated'         => $outcome['truncated'],
			'blocks'            => $outcome['blocks'],
			/* translators: 1: entry count, 2: post ID */
			'message'           => sprintf( __( 'Outlined %1$d block(s) for post #%2$d.', 'acrossai-abilities-manager' ), count( $outcome['blocks'] ), $post_id ),
		);
	}

	/**
	 * Build a flat outline from a pre-parsed block tree. Static + pure so
	 * tests can call it against a synthetic fixture without needing a live
	 * post or parse_blocks().
	 *
	 * @param array<int, array<string, mixed>> $blocks        Root block tree.
	 * @param int[]                            $start_path    Empty = root.
	 * @param int                              $depth         -1 unlimited, 0 start only, N levels below.
	 * @param int                              $max_text      0 omits preview.
	 * @param string[]                         $block_names   Empty = all types.
	 * @param string                           $contains      Empty = no substring filter.
	 * @param bool                             $include_attrs Include block attrs in each entry.
	 * @param int                              $max_results   1..2000; truncated:true when reached.
	 * @return array{blocks: array<int, array<string, mixed>>, truncated: bool}
	 */
	public static function build_outline(
		array $blocks,
		array $start_path,
		int $depth,
		int $max_text,
		array $block_names,
		string $contains,
		bool $include_attrs,
		int $max_results
	): array {
		$entries      = array();
		$matched      = 0;
		$truncated    = false;
		$type_filter  = ! empty( $block_names ) ? array_flip( $block_names ) : null;
		$has_contains = '' !== $contains;
		$contains_lc  = $has_contains ? mb_strtolower( $contains, 'UTF-8' ) : '';

		Block_Tree::walk_tree(
			$blocks,
			static function ( array $block, array $path ) use (
				&$entries,
				&$matched,
				&$truncated,
				$start_path,
				$depth,
				$max_text,
				$type_filter,
				$has_contains,
				$contains_lc,
				$include_attrs,
				$max_results
			): void {
				// NOTE: deliberately no early return once the cap is reached. Stopping the walk
				// there is what made `total` report the number RETURNED rather than the number
				// MATCHED -- with max_results: 3 on a 40-block post it said total: 3. Collection
				// stops at the cap; counting does not.
				// Only consider the subtree rooted at $start_path.
				if ( ! self::path_starts_with( $path, $start_path ) ) {
					return;
				}
				// Depth check counts levels below the subtree root.
				$relative_depth = count( $path ) - count( $start_path );
				if ( -1 !== $depth && $relative_depth > $depth ) {
					return;
				}
				// Whitespace nodes (null blockName) consume index positions
				// but never appear as output entries.
				$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
				if ( '' === $block_name ) {
					return;
				}
				// block_names filter — applies to output, not traversal.
				if ( null !== $type_filter && ! isset( $type_filter[ $block_name ] ) ) {
					return;
				}
				$inner_html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
				$preview    = $max_text > 0 ? self::extract_preview( $inner_html, $max_text ) : '';

				if ( $has_contains ) {
					$compare = $max_text > 0 ? mb_strtolower( $preview, 'UTF-8' ) : mb_strtolower( self::extract_preview( $inner_html, 500 ), 'UTF-8' );
					if ( false === mb_strpos( $compare, $contains_lc ) ) {
						return;
					}
				}

				$entry = array(
					'path'       => array_values( $path ),
					'blockName'  => $block_name,
					'childCount' => self::count_named_children( $block ),
					'bytes'      => strlen( $inner_html ),
					'subtree_bytes' => self::subtree_bytes( $block ),
				);
				if ( $max_text > 0 ) {
					$entry['text'] = $preview;
				}
				if ( $include_attrs ) {
					$entry['attrs'] = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				}
				++$matched;

				if ( count( $entries ) < $max_results ) {
					$entries[] = $entry;
				} else {
					$truncated = true;
				}
			}
		);

		return array(
			'blocks'    => $entries,
			'matched'   => $matched,
			'truncated' => $truncated,
		);
	}

	/**
	 * Extract a short text preview from the block's own innerHTML: strip
	 * tags, decode HTML entities, collapse whitespace runs to single
	 * spaces, trim, then multibyte-safe truncate to $max_text.
	 *
	 * @param string $inner_html
	 * @param int    $max_text
	 * @return string
	 */
	private static function extract_preview( string $inner_html, int $max_text ): string {
		if ( '' === $inner_html ) {
			return '';
		}
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $inner_html ) : strip_tags( $inner_html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? '';
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( mb_strlen( $text, 'UTF-8' ) <= $max_text ) {
			return $text;
		}
		return mb_substr( $text, 0, $max_text, 'UTF-8' );
	}

	/**
	 * True when $path begins with every element of $prefix. Empty $prefix
	 * matches every path.
	 *
	 * @param int[] $path
	 * @param int[] $prefix
	 * @return bool
	 */
	private static function path_starts_with( array $path, array $prefix ): bool {
		if ( array() === $prefix ) {
			return true;
		}
		if ( count( $prefix ) > count( $path ) ) {
			return false;
		}
		return array_slice( $path, 0, count( $prefix ) ) === $prefix;
	}

	/**
	 * Total innerHTML size of a block and everything nested inside it.
	 *
	 * `bytes` is the block's own markup, which for a container -- a group, a columns block -- is
	 * close to nothing while the subtree beneath it may be most of the post. Reporting both is what
	 * lets a caller tell "this block is small" from "this block is a small wrapper around 40 KB".
	 *
	 * @since  0.0.36
	 * @param  array<string, mixed> $block Parsed block.
	 * @return int
	 */
	private static function subtree_bytes( array $block ): int {
		$bytes = strlen( isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '' );

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $child ) {
				if ( is_array( $child ) ) {
					$bytes += self::subtree_bytes( $child );
				}
			}
		}

		return $bytes;
	}

	/**
	 * Count innerBlocks entries whose blockName is a non-empty string.
	 *
	 * @param array<string, mixed> $block
	 * @return int
	 */
	private static function count_named_children( array $block ): int {
		if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $block['innerBlocks'] as $child ) {
			if ( is_array( $child ) && isset( $child['blockName'] ) && '' !== (string) $child['blockName'] ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Coerce the path input to an int[]. Rejects any non-integer element by
	 * returning an empty array — matching the shape Add_Block / Remove_Block
	 * use.
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
	 * Sanitize the block_names filter to a string[]. Empty / non-array input
	 * returns [] which the outline treats as "no type filter".
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	private static function sanitize_block_names( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Build the message returned when the caller-supplied start-at path
	 * does not resolve. Names the depth that failed and how many blocks
	 * exist at that level (raw-index count, including whitespace nodes).
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
