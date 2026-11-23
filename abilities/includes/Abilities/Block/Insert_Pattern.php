<?php
/**
 * Feature 066 — expand a saved block pattern at a canonical path.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.0.24
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Tree;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Detector;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Helper;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a block pattern by slug (across db / theme / plugin), parse its
 * content, and insert its constituent blocks at the given parent_path +
 * index. Delegates resolution to the shared Pattern_Detector — no source
 * scanning is duplicated here.
 */
class Insert_Pattern extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/insert-pattern',
			'args' => array(
				'label'               => __( 'Insert Pattern', 'acrossai-abilities-manager' ),
				'description'         => __( 'Resolve a block pattern by slug (across database, active theme, and installed plugins) and insert its constituent blocks at the given parent_path and sibling index. Refuses ambiguous slugs unless a "source" (and optionally "theme_type" or "plugin_slug") is provided.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'parent_path' => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'index'       => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'slug'        => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'source'      => array(
							'type' => 'string',
							'enum' => array( 'db', 'theme', 'plugin' ),
						),
						'theme_type'  => array(
							'type' => 'string',
							'enum' => array( 'parent', 'child' ),
						),
						'plugin_slug' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'parent_path', 'index', 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'post_id'        => array( 'type' => 'integer' ),
						'inserted_paths' => array( 'type' => 'array' ),
						'count'          => array( 'type' => 'integer' ),
						'message'        => array( 'type' => 'string' ),
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
		$post_id     = absint( $input['post_id'] ?? 0 );
		$parent_path = self::sanitize_path( $input['parent_path'] ?? array() );
		$index       = (int) ( $input['index'] ?? 0 );
		$slug        = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$source      = isset( $input['source'] ) ? (string) $input['source'] : '';
		$theme_type  = isset( $input['theme_type'] ) ? (string) $input['theme_type'] : '';
		$plugin_slug = isset( $input['plugin_slug'] ) ? (string) $input['plugin_slug'] : '';

		if ( '' === $slug ) {
			return self::fail( $post_id, 'invalid_slug', __( 'A non-empty pattern slug is required.', 'acrossai-abilities-manager' ) );
		}

		$locations = Pattern_Detector::locate( $slug );
		$selected  = Pattern_Detector::select( $locations, $source, $theme_type, $plugin_slug );
		if ( is_wp_error( $selected ) ) {
			return self::fail( $post_id, (string) $selected->get_error_code(), (string) $selected->get_error_message() );
		}

		$pattern_content = self::load_pattern_content( $selected );
		if ( '' === $pattern_content ) {
			return self::fail( $post_id, 'pattern_empty', __( 'The resolved pattern has no content to insert.', 'acrossai-abilities-manager' ) );
		}

		$pattern_blocks = parse_blocks( $pattern_content );
		if ( ! is_array( $pattern_blocks ) || array() === $pattern_blocks ) {
			return self::fail( $post_id, 'pattern_empty', __( 'The resolved pattern contains no parseable blocks.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return self::fail( $post_id, (string) $blocks->get_error_code(), (string) $blocks->get_error_message() );
		}

		$inserted_paths = array();
		$cursor         = $index;
		foreach ( $pattern_blocks as $pattern_block ) {
			if ( ! is_array( $pattern_block ) ) {
				continue;
			}
			if ( ! Block_Tree::insert_at_path( $blocks, $parent_path, $cursor, $pattern_block ) ) {
				return self::fail( $post_id, 'invalid_path', __( 'Parent path does not resolve.', 'acrossai-abilities-manager' ) );
			}
			$inserted_paths[] = array_merge( $parent_path, array( $cursor ) );
			++$cursor;
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

		return array(
			'success'        => true,
			'post_id'        => $post_id,
			'inserted_paths' => $inserted_paths,
			'count'          => count( $inserted_paths ),
			/* translators: 1: count, 2: slug, 3: post ID */
			'message'        => sprintf( __( 'Inserted %1$d blocks from pattern "%2$s" into post #%3$d.', 'acrossai-abilities-manager' ), count( $inserted_paths ), $slug, $post_id ),
		);
	}

	/**
	 * Load raw block-markup content from a Pattern_Detector location.
	 *
	 * @param array<string, mixed> $location
	 * @return string
	 */
	private static function load_pattern_content( array $location ): string {
		$source = (string) ( $location['source'] ?? '' );
		if ( 'db' === $source ) {
			$post_id = (int) ( $location['post_id'] ?? 0 );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;
			return $post instanceof \WP_Post ? (string) $post->post_content : '';
		}

		$path = (string) ( $location['path'] ?? '' );
		if ( '' === $path || ! is_file( $path ) ) {
			return '';
		}
		$contents = (string) @file_get_contents( $path );
		if ( '' === $contents ) {
			return '';
		}
		$parsed = Pattern_Helper::parse_file( $contents );
		return isset( $parsed['body'] ) ? (string) $parsed['body'] : '';
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
