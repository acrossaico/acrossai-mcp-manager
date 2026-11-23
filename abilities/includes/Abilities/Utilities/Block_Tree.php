<?php
/**
 * Feature 066 — shared block-tree utility used by every content ability that
 * reads or mutates a post's Gutenberg block tree.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.24
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

use WP_Error;
use WP_Post;
use WP_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Pure tree-addressing primitives operating on the array shape returned by
 * WordPress core parse_blocks(). Every method that mutates does so on a
 * caller-provided reference; there is no persistence at this layer.
 *
 * Canonical path scheme: an ordered array of zero-based non-negative integers.
 * [] denotes the root list. [i] the i-th top-level block. [i, j] the j-th
 * child of that block. And so on recursively.
 *
 * IO-adjacent helpers (parse_post_blocks, assert_post_type_editable) exist so
 * every ability that mutates a post can share one identical guard sequence.
 */
final class Block_Tree {

	/**
	 * Post types that internal WordPress subsystems reserve and where the
	 * block-editor round-trip does not apply. Extracted from the legacy
	 * inline whitelist in Update_Post_Block.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_POST_TYPES = array(
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
	);

	/**
	 * Namespace/name pattern for a Gutenberg block. Both segments accept
	 * alphanumerics, underscore, and hyphen. Extracted from Update_Post_Block.
	 */
	private const BLOCK_NAME_PATTERN = '/^[A-Za-z0-9_-]+\/[A-Za-z0-9_-]+$/';

	/**
	 * Validate a Gutenberg block name.
	 *
	 * @param string $name Candidate block name (e.g. "core/paragraph").
	 * @return bool
	 */
	public static function validate_block_name( string $name ): bool {
		return '' !== $name && 1 === preg_match( self::BLOCK_NAME_PATTERN, $name );
	}

	/**
	 * Verify a post can be edited via the block-tree abilities.
	 *
	 * Rejects internal CPTs and post types that do not participate in the
	 * WordPress editor surface (no public exposure, no UI, no REST).
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error True on success; WP_Error on any failure.
	 */
	public static function assert_post_type_editable( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'acrossai-abilities-manager' )
			);
		}
		$post_type_obj = get_post_type_object( (string) $post->post_type );
		if ( ! $post_type_obj instanceof WP_Post_Type
			|| in_array( $post_type_obj->name, self::FORBIDDEN_POST_TYPES, true )
			|| ! ( (bool) $post_type_obj->public || (bool) $post_type_obj->show_ui || (bool) $post_type_obj->show_in_rest ) ) {
			return new WP_Error(
				'post_type_forbidden',
				__( 'This post type is not editable through this ability.', 'acrossai-abilities-manager' )
			);
		}
		return true;
	}

	/**
	 * Parse a post's block tree with post-existence and capability guards.
	 *
	 * Callers that intend to mutate should pass 'edit' as $cap; reads pass
	 * 'read'. The caller receives the raw parse_blocks() output; annotation
	 * with paths is a separate step.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $cap     Either 'edit' or 'read'.
	 * @return array<int, array<string, mixed>>|WP_Error Parsed blocks or error.
	 */
	public static function parse_post_blocks( int $post_id, string $cap = 'edit' ) {
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'invalid_post_id',
				__( 'A valid post_id is required.', 'acrossai-abilities-manager' )
			);
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'acrossai-abilities-manager' )
			);
		}
		$editable = self::assert_post_type_editable( $post_id );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}
		$required = 'read' === $cap ? 'read_post' : 'edit_post';
		if ( ! current_user_can( $required, $post_id ) ) {
			return new WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to access this post.', 'acrossai-abilities-manager' )
			);
		}

		$blocks = parse_blocks( (string) $post->post_content );
		return is_array( $blocks ) ? $blocks : array();
	}

	/**
	 * Depth-first walk over a block tree, invoking $visitor for every node
	 * with its computed path. $visitor receives ( array $block, int[] $path ).
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed block tree.
	 * @param callable                         $visitor fn(array $block, array $path): void
	 * @param int[]                            $prefix  Internal recursion parameter.
	 * @return void
	 */
	public static function walk_tree( array $blocks, callable $visitor, array $prefix = array() ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$path = array_merge( $prefix, array( (int) $index ) );
			$visitor( $block, $path );
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			if ( ! empty( $inner ) ) {
				self::walk_tree( array_values( $inner ), $visitor, $path );
			}
		}
	}

	/**
	 * Return a deep copy of the tree with each node's `path` key populated.
	 * Non-mutating.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed block tree.
	 * @param int[]                            $prefix  Internal recursion parameter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function annotate_with_paths( array $blocks, array $prefix = array() ): array {
		$out = array();
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$path          = array_merge( $prefix, array( (int) $index ) );
			$block['path'] = $path;
			$inner         = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			if ( ! empty( $inner ) ) {
				$block['innerBlocks'] = self::annotate_with_paths( array_values( $inner ), $path );
			}
			$out[] = $block;
		}
		return $out;
	}

	/**
	 * Return the block at $path or null if the path does not resolve.
	 * $path may not be empty (the root itself is not a block).
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed block tree.
	 * @param int[]                            $path   Canonical block path.
	 * @return array<string, mixed>|null
	 */
	public static function get_at_path( array $blocks, array $path ): ?array {
		if ( array() === $path ) {
			return null;
		}
		$cursor = $blocks;
		$last   = count( $path ) - 1;
		foreach ( $path as $depth => $index ) {
			if ( ! is_int( $index ) || $index < 0 || ! isset( $cursor[ $index ] ) || ! is_array( $cursor[ $index ] ) ) {
				return null;
			}
			if ( $depth === $last ) {
				return $cursor[ $index ];
			}
			$cursor = isset( $cursor[ $index ]['innerBlocks'] ) && is_array( $cursor[ $index ]['innerBlocks'] )
				? array_values( $cursor[ $index ]['innerBlocks'] )
				: array();
		}
		return null;
	}

	/**
	 * Insert $new_block into the children of the block at $parent_path at
	 * $index. If $index >= sibling count, append at the end. $parent_path may
	 * be empty to insert into the root list.
	 *
	 * Mutates $blocks by reference.
	 *
	 * @param array<int, array<string, mixed>> $blocks       Root block tree.
	 * @param int[]                            $parent_path  Path of the parent (empty = root).
	 * @param int                              $index        Zero-based insert position.
	 * @param array<string, mixed>             $new_block    Block payload to insert.
	 * @return bool True on success.
	 */
	public static function insert_at_path( array &$blocks, array $parent_path, int $index, array $new_block ): bool {
		if ( $index < 0 ) {
			return false;
		}
		$new_block = self::normalize_block_shape( $new_block );

		if ( array() === $parent_path ) {
			$count = count( $blocks );
			$pos   = min( $index, $count );
			array_splice( $blocks, $pos, 0, array( $new_block ) );
			$blocks = array_values( $blocks );
			return true;
		}

		$parent = &self::locate_parent_ref( $blocks, $parent_path );
		if ( null === $parent ) {
			return false;
		}
		if ( ! isset( $parent['innerBlocks'] ) || ! is_array( $parent['innerBlocks'] ) ) {
			$parent['innerBlocks'] = array();
		}
		$children = array_values( $parent['innerBlocks'] );
		$count    = count( $children );
		$pos      = min( $index, $count );
		array_splice( $children, $pos, 0, array( $new_block ) );
		$parent['innerBlocks'] = array_values( $children );

		// Keep innerContent placeholders consistent — WordPress core expects
		// one null entry per child block when there is HTML surrounding them.
		// If innerContent is currently a simple wrapper (only nulls or empty),
		// grow the null-array to match the new child count. Otherwise leave
		// it alone and let serialize_blocks resolve the layout on the next
		// parse round-trip.
		if ( isset( $parent['innerContent'] ) && is_array( $parent['innerContent'] ) && self::is_null_only( $parent['innerContent'] ) ) {
			$parent['innerContent'] = array_fill( 0, count( $parent['innerBlocks'] ), null );
		}
		return true;
	}

	/**
	 * Remove the block at $path and return it, or null if the path does not
	 * resolve. $path may not be empty.
	 *
	 * Mutates $blocks by reference.
	 *
	 * @param array<int, array<string, mixed>> $blocks Root block tree.
	 * @param int[]                            $path   Canonical block path.
	 * @return array<string, mixed>|null Removed block or null.
	 */
	public static function remove_at_path( array &$blocks, array $path ): ?array {
		if ( array() === $path ) {
			return null;
		}
		$parent_path = $path;
		$leaf        = (int) array_pop( $parent_path );

		if ( array() === $parent_path ) {
			if ( ! isset( $blocks[ $leaf ] ) || ! is_array( $blocks[ $leaf ] ) ) {
				return null;
			}
			$removed = $blocks[ $leaf ];
			array_splice( $blocks, $leaf, 1 );
			$blocks = array_values( $blocks );
			return $removed;
		}

		$parent = &self::locate_parent_ref( $blocks, $parent_path );
		if ( null === $parent || ! isset( $parent['innerBlocks'] ) || ! is_array( $parent['innerBlocks'] ) ) {
			return null;
		}
		$children = array_values( $parent['innerBlocks'] );
		if ( ! isset( $children[ $leaf ] ) ) {
			return null;
		}
		$removed = $children[ $leaf ];
		array_splice( $children, $leaf, 1 );
		$parent['innerBlocks'] = array_values( $children );

		if ( isset( $parent['innerContent'] ) && is_array( $parent['innerContent'] ) && self::is_null_only( $parent['innerContent'] ) ) {
			$parent['innerContent'] = array_fill( 0, count( $parent['innerBlocks'] ), null );
		}
		return $removed;
	}

	/**
	 * Replace the block at $path with $new_block. Returns false when the path
	 * does not resolve.
	 *
	 * Mutates $blocks by reference.
	 *
	 * @param array<int, array<string, mixed>> $blocks     Root block tree.
	 * @param int[]                            $path       Canonical block path.
	 * @param array<string, mixed>             $new_block  Replacement block.
	 * @return bool
	 */
	public static function replace_at_path( array &$blocks, array $path, array $new_block ): bool {
		if ( array() === $path ) {
			return false;
		}
		$parent_path = $path;
		$leaf        = (int) array_pop( $parent_path );
		$new_block   = self::normalize_block_shape( $new_block );

		if ( array() === $parent_path ) {
			if ( ! isset( $blocks[ $leaf ] ) || ! is_array( $blocks[ $leaf ] ) ) {
				return false;
			}
			$blocks[ $leaf ] = $new_block;
			return true;
		}

		$parent = &self::locate_parent_ref( $blocks, $parent_path );
		if ( null === $parent || ! isset( $parent['innerBlocks'] ) || ! is_array( $parent['innerBlocks'] ) ) {
			return false;
		}
		$children = array_values( $parent['innerBlocks'] );
		if ( ! isset( $children[ $leaf ] ) ) {
			return false;
		}
		$children[ $leaf ]     = $new_block;
		$parent['innerBlocks'] = array_values( $children );
		return true;
	}

	/**
	 * Atomically move the block at $from to child position $to_index of the
	 * parent at $to_parent. Refuses moves whose destination lies inside the
	 * source's own subtree (would create a cycle).
	 *
	 * Mutates $blocks by reference.
	 *
	 * @param array<int, array<string, mixed>> $blocks    Root block tree.
	 * @param int[]                            $from      Source block path.
	 * @param int[]                            $to_parent Destination parent path.
	 * @param int                              $to_index  Zero-based destination sibling index.
	 * @return true|WP_Error
	 */
	public static function move( array &$blocks, array $from, array $to_parent, int $to_index ) {
		if ( array() === $from ) {
			return new WP_Error(
				'invalid_path',
				__( 'Source path must not be empty.', 'acrossai-abilities-manager' )
			);
		}
		if ( $to_index < 0 ) {
			return new WP_Error(
				'invalid_destination',
				__( 'Destination index must be zero or greater.', 'acrossai-abilities-manager' )
			);
		}
		if ( self::path_starts_with( $to_parent, $from ) ) {
			return new WP_Error(
				'descendant_destination',
				__( 'Destination lies within the source subtree.', 'acrossai-abilities-manager' )
			);
		}
		if ( null === self::get_at_path( $blocks, $from ) ) {
			return new WP_Error(
				'invalid_path',
				__( 'Source path does not resolve to a block.', 'acrossai-abilities-manager' )
			);
		}
		if ( array() !== $to_parent && null === self::get_at_path( $blocks, $to_parent ) ) {
			return new WP_Error(
				'invalid_destination',
				__( 'Destination parent path does not resolve to a block.', 'acrossai-abilities-manager' )
			);
		}

		$moved = self::remove_at_path( $blocks, $from );
		if ( null === $moved ) {
			return new WP_Error(
				'invalid_path',
				__( 'Source path does not resolve to a block.', 'acrossai-abilities-manager' )
			);
		}

		// Adjust destination index if the removal shifted siblings in the
		// same parent. Only relevant when to_parent == parent(from) and the
		// source sat at a lower index than the destination.
		$from_parent = $from;
		array_pop( $from_parent );
		if ( $from_parent === $to_parent ) {
			$source_index = (int) end( $from );
			if ( $source_index < $to_index ) {
				--$to_index;
			}
		}

		if ( ! self::insert_at_path( $blocks, $to_parent, $to_index, $moved ) ) {
			// Restore in case insert failed unexpectedly.
			self::insert_at_path( $blocks, $from_parent, (int) end( $from ), $moved );
			return new WP_Error(
				'invalid_destination',
				__( 'Failed to insert at destination.', 'acrossai-abilities-manager' )
			);
		}
		return true;
	}

	/**
	 * Validate a block's attributes against its registered block type's
	 * schema. Soft-fails (returns true) when the block type is not
	 * registered — the abilities layer must remain usable for custom or
	 * plugin-supplied blocks not present on this site.
	 *
	 * @param string               $block_name Block name (namespace/name).
	 * @param array<string, mixed> $attrs      Candidate attributes.
	 * @return true|WP_Error
	 */
	public static function validate_attributes_against_schema( string $block_name, array $attrs ) {
		if ( ! Block_Info::registry_available() ) {
			return true;
		}
		$block_type = Block_Info::get_block( $block_name );
		if ( null === $block_type ) {
			return true;
		}
		$schema = $block_type->attributes;
		if ( ! is_array( $schema ) || array() === $schema ) {
			return true;
		}
		foreach ( $attrs as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
				continue;
			}
			$declared = isset( $schema[ $key ]['type'] ) ? (array) $schema[ $key ]['type'] : array();
			if ( array() === $declared ) {
				continue;
			}
			if ( ! self::value_matches_type( $value, $declared ) ) {
				return new WP_Error(
					'invalid_attributes',
					sprintf(
						/* translators: 1: attribute name, 2: block name */
						__( 'Attribute "%1$s" does not match the schema declared by block type "%2$s".', 'acrossai-abilities-manager' ),
						(string) $key,
						$block_name
					)
				);
			}
		}
		return true;
	}

	/**
	 * Locate a reference to the block at $path so callers can mutate it in
	 * place. Returns null if the path does not resolve.
	 *
	 * @param array<int, array<string, mixed>> $blocks Root block tree.
	 * @param int[]                            $path   Non-empty path.
	 * @return array<string, mixed>|null
	 */
	private static function &locate_parent_ref( array &$blocks, array $path ): ?array {
		$null   = null;
		$cursor = &$blocks;
		$last   = count( $path ) - 1;
		foreach ( $path as $depth => $index ) {
			if ( ! is_int( $index ) || $index < 0 || ! isset( $cursor[ $index ] ) || ! is_array( $cursor[ $index ] ) ) {
				return $null;
			}
			if ( $depth === $last ) {
				$ref = &$cursor[ $index ];
				return $ref;
			}
			if ( ! isset( $cursor[ $index ]['innerBlocks'] ) || ! is_array( $cursor[ $index ]['innerBlocks'] ) ) {
				return $null;
			}
			$cursor = &$cursor[ $index ]['innerBlocks'];
		}
		return $null;
	}

	/**
	 * True when $candidate begins with every element of $prefix.
	 *
	 * @param int[] $candidate
	 * @param int[] $prefix
	 * @return bool
	 */
	private static function path_starts_with( array $candidate, array $prefix ): bool {
		if ( count( $prefix ) > count( $candidate ) ) {
			return false;
		}
		return array_slice( $candidate, 0, count( $prefix ) ) === $prefix;
	}

	/**
	 * True when every element of the array is null.
	 *
	 * @param array<int, mixed> $arr
	 * @return bool
	 */
	private static function is_null_only( array $arr ): bool {
		foreach ( $arr as $item ) {
			if ( null !== $item ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Bring a caller-supplied block payload up to the shape parse_blocks()
	 * would have produced — fill in missing structural keys.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function normalize_block_shape( array $block ): array {
		if ( isset( $block['name'] ) && ! isset( $block['blockName'] ) ) {
			$block['blockName'] = (string) $block['name'];
			unset( $block['name'] );
		}
		if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			$block['attrs'] = array();
		}
		if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = array();
		}
		if ( ! isset( $block['innerHTML'] ) ) {
			$block['innerHTML'] = '';
		}
		if ( ! isset( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			$block['innerContent'] = '' === $block['innerHTML'] && array() === $block['innerBlocks']
				? array()
				: array( (string) $block['innerHTML'] );
		}
		return $block;
	}

	/**
	 * Naive JSON-schema type match — good enough for the "obvious wrong type"
	 * guard we want. Registered block-type attributes typically declare one
	 * of: string, number, integer, boolean, array, object, null.
	 *
	 * @param mixed         $value
	 * @param array<string> $types
	 * @return bool
	 */
	private static function value_matches_type( $value, array $types ): bool {
		foreach ( $types as $type ) {
			$type = strtolower( (string) $type );
			switch ( $type ) {
				case 'string':
					if ( is_string( $value ) ) {
						return true;
					}
					break;
				case 'number':
					if ( is_int( $value ) || is_float( $value ) ) {
						return true;
					}
					break;
				case 'integer':
					if ( is_int( $value ) ) {
						return true;
					}
					break;
				case 'boolean':
					if ( is_bool( $value ) ) {
						return true;
					}
					break;
				case 'array':
					if ( is_array( $value ) && array_is_list( $value ) ) {
						return true;
					}
					break;
				case 'object':
					if ( is_array( $value ) || is_object( $value ) ) {
						return true;
					}
					break;
				case 'null':
					if ( null === $value ) {
						return true;
					}
					break;
			}
		}
		return false;
	}
}
