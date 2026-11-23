<?php
/**
 * Feature 067 — Elementor document loading and saving helpers.
 *
 * Central utility for every Elementor ability that reads or writes
 * _elementor_data post meta. Provides:
 *   • post-existence + capability guards
 *   • JSON decode/encode with wp_slash policy (Feature 067 R4)
 *   • cache invalidation (Feature 067 R8)
 *   • tree traversal (find element by ID, insert/remove/reorder, reassign IDs)
 *
 * All methods are pure static — no instantiation.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor;

use WP_Error;
use WP_Post;
use WP_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor document repository — load, mutate, save, invalidate.
 */
final class Document_Repository {

	/**
	 * Post types that internal WordPress subsystems reserve. Match the same
	 * whitelist used by Block_Tree / Update_Post_Block.
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
	 * Regex for a valid 7-character Elementor element ID (hex).
	 */
	private const ELEMENT_ID_PATTERN = '/^[a-f0-9]{7}$/';

	// ------------------------------------------------------------------
	// Guards
	// ------------------------------------------------------------------

	/**
	 * Verify a post can be edited via Elementor abilities.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
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
	 * Verify Elementor is present at runtime (defense-in-depth per R1).
	 *
	 * @return true|WP_Error
	 */
	public static function assert_elementor_available() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error(
				'elementor_missing',
				__( 'Elementor is not installed.', 'acrossai-abilities-manager' )
			);
		}
		return true;
	}

	/**
	 * Verify Elementor Pro is present at runtime.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_elementor_pro_available() {
		if ( ! class_exists( '\ElementorPro\Plugin' ) && ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return new WP_Error(
				'elementor_pro_missing',
				__( 'Elementor Pro is not installed.', 'acrossai-abilities-manager' )
			);
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Load
	// ------------------------------------------------------------------

	/**
	 * Load the raw _elementor_data meta string for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Raw JSON string or empty string.
	 */
	public static function get_raw_data( int $post_id ): string {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Decode the _elementor_data meta into a PHP array.
	 *
	 * @param string          $raw          Raw JSON string.
	 * @param string|null     $decode_error Out-param populated on decode failure.
	 * @return array<int, array<string, mixed>>
	 */
	public static function decode_data( string $raw, ?string &$decode_error = null ): array {
		$decode_error = null;
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decode_error = json_last_error_msg();
			return array();
		}
		return $decoded;
	}

	/**
	 * Load a post's decoded Elementor document with post-existence, capability
	 * and post-type guards. Returns a WP_Error on any guard failure.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $cap     'edit' or 'read' (default 'edit').
	 * @return array<string, mixed>|WP_Error {
	 *     @type WP_Post              $post
	 *     @type string               $raw_data
	 *     @type array                $data           Parsed Elementor tree.
	 *     @type string|null          $decode_error
	 *     @type string               $edit_mode
	 *     @type array                $page_settings
	 * }
	 */
	public static function load_document( int $post_id, string $cap = 'edit' ) {
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'invalid_post_id',
				__( 'A valid post_id is required.', 'acrossai-abilities-manager' )
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

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'acrossai-abilities-manager' )
			);
		}

		$raw          = self::get_raw_data( $post_id );
		$decode_error = null;
		$data         = self::decode_data( $raw, $decode_error );
		$settings     = get_post_meta( $post_id, '_elementor_page_settings', true );

		return array(
			'post'          => $post,
			'raw_data'      => $raw,
			'data'          => $data,
			'decode_error'  => $decode_error,
			'edit_mode'     => get_post_meta( $post_id, '_elementor_edit_mode', true ) ?: '',
			'page_settings' => is_array( $settings ) ? $settings : array(),
		);
	}

	// ------------------------------------------------------------------
	// Save
	// ------------------------------------------------------------------

	/**
	 * Save Elementor document data with the mandatory wp_slash policy and
	 * cache invalidation. Feature 067 R4 + R8.
	 *
	 * @param int                              $post_id       Post ID.
	 * @param array<int, array<string, mixed>> $data          Parsed Elementor tree.
	 * @param string                           $cache_scope   'none' | 'post' | 'site'.
	 * @return true|WP_Error
	 */
	public static function save_data( int $post_id, array $data, string $cache_scope = 'post' ) {
		$json = wp_json_encode( array_values( $data ) );
		if ( false === $json ) {
			return new WP_Error(
				'json_encode_failed',
				__( 'Failed to encode Elementor data.', 'acrossai-abilities-manager' )
			);
		}
		// R4: wp_slash the JSON so update_post_meta's internal wp_unslash does not strip escapes.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

		self::invalidate_cache( $post_id, $cache_scope );
		return true;
	}

	/**
	 * Invalidate Elementor and WordPress caches for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $scope   'none' | 'post' | 'site'.
	 * @return array<string, mixed> Details of what was cleared.
	 */
	public static function invalidate_cache( int $post_id, string $scope = 'post' ): array {
		$cleared = array( 'scope' => $scope, 'post_cache' => false, 'css_meta' => false, 'files_manager' => false );

		if ( 'none' === $scope ) {
			return $cleared;
		}

		if ( 'post' === $scope || 'site' === $scope ) {
			clean_post_cache( $post_id );
			$cleared['post_cache'] = true;

			// Delete the per-post CSS meta — Elementor regenerates on next render.
			if ( '' !== get_post_meta( $post_id, '_elementor_css', true ) ) {
				delete_post_meta( $post_id, '_elementor_css' );
				$cleared['css_meta'] = true;
			}
		}

		if ( 'site' === $scope && class_exists( '\Elementor\Plugin' ) ) {
			$instance = \Elementor\Plugin::$instance;
			if ( isset( $instance->files_manager ) && method_exists( $instance->files_manager, 'clear_cache' ) ) {
				$instance->files_manager->clear_cache();
				$cleared['files_manager'] = true;
			}
		}
		return $cleared;
	}

	// ------------------------------------------------------------------
	// Tree traversal
	// ------------------------------------------------------------------

	/**
	 * Depth-first walk over an Elementor tree.
	 *
	 * @param array<int, array<string, mixed>> $elements Tree.
	 * @param callable                         $visitor  fn( array $element, array $path ): void
	 * @param string[]                         $prefix   Recursion parameter (path of parent IDs).
	 * @return void
	 */
	public static function walk_tree( array $elements, callable $visitor, array $prefix = array() ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id   = isset( $element['id'] ) ? (string) $element['id'] : '';
			$path = '' !== $id ? array_merge( $prefix, array( $id ) ) : $prefix;
			$visitor( $element, $path );
			$inner = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			if ( ! empty( $inner ) ) {
				self::walk_tree( array_values( $inner ), $visitor, $path );
			}
		}
	}

	/**
	 * Locate an element by ID and return the element + its path (parent ID chain).
	 *
	 * @param array<int, array<string, mixed>> $elements Root tree.
	 * @param string                           $element_id Target ID.
	 * @return array<string, mixed>|null { element, path } or null.
	 */
	public static function find_element_by_id( array $elements, string $element_id ): ?array {
		if ( ! self::is_valid_element_id( $element_id ) ) {
			return null;
		}
		$found = null;
		self::walk_tree(
			$elements,
			static function ( array $element, array $path ) use ( &$found, $element_id ): void {
				if ( isset( $element['id'] ) && (string) $element['id'] === $element_id && null === $found ) {
					$parent_path = $path;
					array_pop( $parent_path );
					$found = array(
						'element' => $element,
						'path'    => $parent_path,
					);
				}
			}
		);
		return $found;
	}

	/**
	 * Find all elements matching a predicate, returning [{element, path}, ...].
	 *
	 * @param array<int, array<string, mixed>> $elements Root tree.
	 * @param callable                         $predicate fn( array $element ): bool
	 * @return array<int, array<string, mixed>> Array of { element, path }.
	 */
	public static function find_elements_where( array $elements, callable $predicate ): array {
		$matches = array();
		self::walk_tree(
			$elements,
			static function ( array $element, array $path ) use ( &$matches, $predicate ): void {
				if ( $predicate( $element ) ) {
					$parent_path = $path;
					array_pop( $parent_path );
					$matches[] = array(
						'element' => $element,
						'path'    => $parent_path,
					);
				}
			}
		);
		return $matches;
	}

	/**
	 * Replace an element in the tree by ID. Mutates $elements by reference.
	 *
	 * @param array<int, array<string, mixed>> $elements   Root tree.
	 * @param string                           $element_id Target ID.
	 * @param array<string, mixed>             $replacement New element (should retain the same id).
	 * @return bool True if replaced.
	 */
	public static function replace_element_by_id( array &$elements, string $element_id, array $replacement ): bool {
		return self::mutate_by_id(
			$elements,
			$element_id,
			static function ( array &$container, int $index ) use ( $replacement ): bool {
				$container[ $index ] = $replacement;
				return true;
			}
		);
	}

	/**
	 * Remove an element from the tree by ID. Returns the removed element or null.
	 *
	 * @param array<int, array<string, mixed>> $elements   Root tree.
	 * @param string                           $element_id Target ID.
	 * @return array<string, mixed>|null Removed element or null.
	 */
	public static function remove_element_by_id( array &$elements, string $element_id ): ?array {
		$removed = null;
		self::mutate_by_id(
			$elements,
			$element_id,
			static function ( array &$container, int $index ) use ( &$removed ): bool {
				$removed = $container[ $index ];
				array_splice( $container, $index, 1 );
				$container = array_values( $container );
				return true;
			}
		);
		return $removed;
	}

	/**
	 * Insert an element at parent_id/position. Pass parent_id=null to insert at root.
	 *
	 * @param array<int, array<string, mixed>> $elements   Root tree.
	 * @param string|null                      $parent_id  Parent element ID or null for root.
	 * @param int                              $position   Zero-based sibling index (appends if >= count).
	 * @param array<string, mixed>             $new_element Element to insert.
	 * @return bool True on success.
	 */
	public static function insert_element( array &$elements, ?string $parent_id, int $position, array $new_element ): bool {
		if ( $position < 0 ) {
			return false;
		}
		if ( null === $parent_id || '' === $parent_id ) {
			$position = min( $position, count( $elements ) );
			array_splice( $elements, $position, 0, array( $new_element ) );
			$elements = array_values( $elements );
			return true;
		}
		if ( ! self::is_valid_element_id( $parent_id ) ) {
			return false;
		}
		return self::mutate_by_id(
			$elements,
			$parent_id,
			static function ( array &$container, int $index ) use ( $position, $new_element ): bool {
				if ( ! isset( $container[ $index ]['elements'] ) || ! is_array( $container[ $index ]['elements'] ) ) {
					$container[ $index ]['elements'] = array();
				}
				$children     = array_values( $container[ $index ]['elements'] );
				$actual_pos   = min( $position, count( $children ) );
				array_splice( $children, $actual_pos, 0, array( $new_element ) );
				$container[ $index ]['elements'] = array_values( $children );
				return true;
			}
		);
	}

	/**
	 * Reorder direct children of a parent (or root if parent_id is null).
	 *
	 * @param array<int, array<string, mixed>> $elements  Root tree.
	 * @param string|null                      $parent_id Parent ID or null for root.
	 * @param string[]                         $ordered_ids New child order.
	 * @return true|WP_Error
	 */
	public static function reorder_children( array &$elements, ?string $parent_id, array $ordered_ids ) {
		$children = null === $parent_id
			? $elements
			: ( self::find_element_by_id( $elements, $parent_id )['element']['elements'] ?? null );

		if ( null === $children || ! is_array( $children ) ) {
			return new WP_Error(
				'element_not_found',
				__( 'Parent element not found.', 'acrossai-abilities-manager' )
			);
		}

		$by_id = array();
		foreach ( $children as $child ) {
			if ( is_array( $child ) && isset( $child['id'] ) ) {
				$by_id[ (string) $child['id'] ] = $child;
			}
		}

		$reordered = array();
		foreach ( $ordered_ids as $id ) {
			$id = (string) $id;
			if ( ! isset( $by_id[ $id ] ) ) {
				return new WP_Error(
					'element_not_found',
					sprintf(
						/* translators: %s: element ID */
						__( 'Child element %s not found under parent.', 'acrossai-abilities-manager' ),
						$id
					)
				);
			}
			$reordered[] = $by_id[ $id ];
			unset( $by_id[ $id ] );
		}
		// Append any children not mentioned in ordered_ids in their original order.
		foreach ( $by_id as $leftover ) {
			$reordered[] = $leftover;
		}

		if ( null === $parent_id ) {
			$elements = array_values( $reordered );
			return true;
		}
		return self::mutate_by_id(
			$elements,
			$parent_id,
			static function ( array &$container, int $index ) use ( $reordered ): bool {
				$container[ $index ]['elements'] = array_values( $reordered );
				return true;
			}
		);
	}

	/**
	 * Recursively assign fresh 7-char hex IDs to an element and every descendant.
	 * Used by duplicate-element.
	 *
	 * @param array<string, mixed> $element Element to reassign.
	 * @return array<string, mixed> Element with new IDs.
	 */
	public static function reassign_subtree_ids( array $element ): array {
		$element['id'] = self::generate_element_id();
		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$element['elements'] = array_map(
				static fn( $child ) => is_array( $child ) ? self::reassign_subtree_ids( $child ) : $child,
				array_values( $element['elements'] )
			);
		}
		return $element;
	}

	/**
	 * Check whether $candidate_path starts with $prefix — used for descendant-guard.
	 *
	 * @param string[] $candidate_path
	 * @param string[] $prefix
	 * @return bool
	 */
	public static function path_starts_with( array $candidate_path, array $prefix ): bool {
		if ( count( $prefix ) > count( $candidate_path ) ) {
			return false;
		}
		return array_slice( $candidate_path, 0, count( $prefix ) ) === $prefix;
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	/**
	 * True if $id matches the Elementor 7-char hex element ID pattern.
	 *
	 * @param string $id
	 * @return bool
	 */
	public static function is_valid_element_id( string $id ): bool {
		return 1 === preg_match( self::ELEMENT_ID_PATTERN, $id );
	}

	/**
	 * Generate a fresh Elementor-style 7-char hex element ID.
	 *
	 * @return string
	 */
	public static function generate_element_id(): string {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
	}

	/**
	 * True if the parsed tree contains any elements.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @return bool
	 */
	public static function is_document_populated( array $elements ): bool {
		return count( $elements ) > 0;
	}

	// ------------------------------------------------------------------
	// Internal recursion helper
	// ------------------------------------------------------------------

	/**
	 * Locate the element by ID and invoke a callback with (container, index).
	 * The callback may mutate the container. Returns true iff the callback was invoked.
	 *
	 * @param array<int, array<string, mixed>> $container Current container (root or elements[]).
	 * @param string                           $target_id Target element ID.
	 * @param callable                         $callback  fn( array &$container, int $index ): bool
	 * @return bool
	 */
	private static function mutate_by_id( array &$container, string $target_id, callable $callback ): bool {
		foreach ( $container as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === $target_id ) {
				return $callback( $container, $index );
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				// Recurse into children by reference.
				if ( self::mutate_by_id( $container[ $index ]['elements'], $target_id, $callback ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
