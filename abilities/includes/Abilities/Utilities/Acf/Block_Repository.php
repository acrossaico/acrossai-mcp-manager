<?php
/**
 * Feature 105 — ACF block registration, introspection and instance editing.
 *
 * **ACF block field values live in `post_content`, not `postmeta`.** An ACF block instance is a
 * Gutenberg block whose `data` attribute carries the field values inline, so `update_field()` is not
 * the write path here and using it would put the values somewhere nothing reads. Everything in this
 * class goes through the block tree instead, reusing `Utilities\Block_Tree` — the same machinery
 * `blocks/add-block` and `blocks/update-post-block` already use — rather than reimplementing block
 * parsing and serialisation.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Tree;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over ACF's block API.
 */
final class Block_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Register a block type.
	 *
	 * @since  0.0.34
	 * @param  array<string,mixed> $block Block settings.
	 * @return array<string,mixed>|WP_Error The registered block.
	 */
	public static function register( array $block ) {
		$registered = \acf_register_block_type( $block );

		if ( ! $registered ) {
			return new WP_Error(
				'block_register_failed',
				__( 'Advanced Custom Fields refused the block registration.', 'acrossai-abilities-manager' )
			);
		}

		return is_array( $registered ) ? $registered : array( 'name' => (string) ( $block['name'] ?? '' ) );
	}

	/**
	 * Every registered ACF block, as ROWS.
	 *
	 * `acf_get_block_types()` returns a name-keyed map; a list is returned instead so an output
	 * property declared `array` does not encode as a JSON object
	 * (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT).
	 *
	 * @since  0.0.34
	 * @return array<int, array<string,mixed>>
	 */
	public static function all(): array {
		$rows = array();

		foreach ( (array) \acf_get_block_types() as $name => $block ) {
			$name   = (string) $name;
			$groups = self::field_groups( $name );

			$rows[] = array(
				'name'             => $name,
				'title'            => isset( $block['title'] ) ? (string) $block['title'] : '',
				'category'         => isset( $block['category'] ) ? (string) $block['category'] : '',
				'description'      => isset( $block['description'] ) ? (string) $block['description'] : '',
				'field_group_keys' => array_values( array_map( static fn( array $g ): string => (string) ( $g['key'] ?? '' ), $groups ) ),
				'field_count'      => self::count_fields( $groups ),
			);
		}

		return $rows;
	}

	/**
	 * One registered block, or an error naming what is available.
	 *
	 * @since  0.0.34
	 * @param  string $name Block name, with or without the `acf/` prefix.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( string $name ) {
		$name  = self::qualify( $name );
		$block = \acf_get_block_type( $name );

		if ( ! is_array( $block ) ) {
			return new WP_Error(
				'unknown_block',
				sprintf(
					/* translators: %s: block name */
					__( 'No ACF block named "%s". Call blocks/list-acf-blocks to see what is registered.', 'acrossai-abilities-manager' ),
					$name
				)
			);
		}

		return $block;
	}

	/**
	 * The field groups bound to a block.
	 *
	 * ACF matches them through the `block` location rule — the same query its own renderer runs
	 * (`pro/blocks.php`).
	 *
	 * @since  0.0.34
	 * @param  string $name Block name.
	 * @return array<int, array<string,mixed>>
	 */
	public static function field_groups( string $name ): array {
		return (array) \acf_get_field_groups( array( 'block' => self::qualify( $name ) ) );
	}

	/**
	 * Every field behind a block, flattened to rows with their sub-fields nested.
	 *
	 * @since  0.0.34
	 * @param  string $name Block name.
	 * @return array<int, array<string,mixed>>
	 */
	public static function fields( string $name ): array {
		$rows = array();

		foreach ( self::field_groups( $name ) as $group ) {
			foreach ( (array) \acf_get_fields( $group ) as $field ) {
				$rows[] = self::field_row( (array) $field );
			}
		}

		return $rows;
	}

	/**
	 * Shape one ACF field definition into a row, recursing into sub-fields.
	 *
	 * @since  0.0.34
	 * @param  array<string,mixed> $field ACF field definition.
	 * @return array<string,mixed>
	 */
	private static function field_row( array $field ): array {
		$row = array(
			'name'     => isset( $field['name'] ) ? (string) $field['name'] : '',
			'key'      => isset( $field['key'] ) ? (string) $field['key'] : '',
			'type'     => isset( $field['type'] ) ? (string) $field['type'] : '',
			'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
			'required' => ! empty( $field['required'] ),
			'default'  => $field['default_value'] ?? null,
		);

		// Repeaters and groups nest under sub_fields; flexible content nests a layout per entry.
		$children = array();

		foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
			$children[] = self::field_row( (array) $sub );
		}

		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			$layout      = (array) $layout;
			$layout_rows = array();

			foreach ( (array) ( $layout['sub_fields'] ?? array() ) as $sub ) {
				$layout_rows[] = self::field_row( (array) $sub );
			}

			$children[] = array(
				'name'     => isset( $layout['name'] ) ? (string) $layout['name'] : '',
				'key'      => isset( $layout['key'] ) ? (string) $layout['key'] : '',
				'type'     => 'layout',
				'label'    => isset( $layout['label'] ) ? (string) $layout['label'] : '',
				'required' => false,
				'default'  => null,
				'fields'   => $layout_rows,
			);
		}

		if ( array() !== $children ) {
			$row['fields'] = $children;
		}

		return $row;
	}

	/**
	 * Insert a block instance into a post.
	 *
	 * Goes through Block_Tree so the post's existing blocks are parsed, mutated and re-serialised by
	 * the same code path every other block ability uses.
	 *
	 * @since  0.0.34
	 * @param  int                 $post_id Post to insert into.
	 * @param  string              $name    Block name.
	 * @param  array<string,mixed> $data    Field values for the block's `data` attribute.
	 * @param  int|null            $index   Position among the post's top-level blocks, or null to append.
	 * @return array{index: int, name: string}|WP_Error
	 */
	public static function insert( int $post_id, string $name, array $data, ?int $index = null ) {
		$name     = self::qualify( $name );
		$editable = Block_Tree::assert_post_type_editable( $post_id );

		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );

		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$at = null === $index ? count( $blocks ) : max( 0, min( $index, count( $blocks ) ) );

		$new_block = array(
			'blockName'    => $name,
			'attrs'        => array(
				'name' => $name,
				'data' => $data,
				'mode' => 'preview',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		if ( ! Block_Tree::insert_at_path( $blocks, array(), $at, $new_block ) ) {
			return new WP_Error( 'block_insert_failed', __( 'Could not insert the block.', 'acrossai-abilities-manager' ) );
		}

		$saved = self::persist( $post_id, $blocks );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'index' => $at,
			'name'  => $name,
		);
	}

	/**
	 * Patch the `data` attribute of one block instance, leaving its other attributes alone.
	 *
	 * @since  0.0.34
	 * @param  int                 $post_id Post holding the block.
	 * @param  int[]               $path    Block path, as used by every other block ability.
	 * @param  array<string,mixed> $data    Field values to merge.
	 * @param  bool                $replace Replace the whole data payload rather than merging.
	 * @return array<string,mixed>|WP_Error The resulting data.
	 */
	public static function update_data( int $post_id, array $path, array $data, bool $replace = false ) {
		$editable = Block_Tree::assert_post_type_editable( $post_id );

		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );

		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$block = Block_Tree::get_at_path( $blocks, $path );

		if ( null === $block ) {
			return new WP_Error(
				'block_not_found',
				__( 'No block at that path. Call blocks/outline-post-blocks to see the paths.', 'acrossai-abilities-manager' )
			);
		}

		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		if ( 0 !== strpos( $name, 'acf/' ) ) {
			return new WP_Error(
				'not_an_acf_block',
				sprintf(
					/* translators: %s: block name */
					__( 'The block at that path is "%s", not an ACF block. Use blocks/update-post-block for ordinary blocks.', 'acrossai-abilities-manager' ),
					'' !== $name ? $name : 'unnamed'
				)
			);
		}

		$existing = isset( $block['attrs']['data'] ) && is_array( $block['attrs']['data'] ) ? $block['attrs']['data'] : array();
		$merged   = $replace ? $data : array_merge( $existing, $data );

		$block['attrs']['data'] = $merged;

		if ( ! Block_Tree::replace_at_path( $blocks, $path, $block ) ) {
			return new WP_Error( 'block_update_failed', __( 'Could not update the block.', 'acrossai-abilities-manager' ) );
		}

		$saved = self::persist( $post_id, $blocks );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $merged;
	}

	/**
	 * Normalise a block name to its `acf/`-prefixed form.
	 *
	 * Callers reasonably supply either, and ACF only answers to the prefixed one.
	 *
	 * @since  0.0.34
	 * @param  string $name Block name.
	 * @return string
	 */
	public static function qualify( string $name ): string {
		$name = trim( $name );

		return 0 === strpos( $name, 'acf/' ) ? $name : 'acf/' . ltrim( $name, '/' );
	}

	/**
	 * Total field count across a set of field groups.
	 *
	 * @since  0.0.34
	 * @param  array<int, array<string,mixed>> $groups Field groups.
	 * @return int
	 */
	private static function count_fields( array $groups ): int {
		$count = 0;

		foreach ( $groups as $group ) {
			$count += count( (array) \acf_get_fields( $group ) );
		}

		return $count;
	}

	/**
	 * Save the mutated tree, mirroring Block\Add_Block::persist().
	 *
	 * @since  0.0.34
	 * @param  int                              $post_id Post ID.
	 * @param  array<int, array<string, mixed>> $blocks  Block tree.
	 * @return int|WP_Error
	 */
	private static function persist( int $post_id, array $blocks ) {
		return wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => serialize_blocks( $blocks ),
			),
			true
		);
	}
}
