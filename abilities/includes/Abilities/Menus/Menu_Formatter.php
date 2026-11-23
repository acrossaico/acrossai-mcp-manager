<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Menus
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Menus;

use WP_Error;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors WP_REST_Menus_Controller / WP_REST_Menu_Items_Controller output
 * (edit context, minus _links/_embedded). Used by every Menus ability so they
 * can drop their rest_do_request() calls without breaking the output shape.
 */
final class Menu_Formatter {

	/**
	 * Map a nav_menu WP_Term into the abilities' canonical menu array.
	 *
	 * @param WP_Term $menu The nav_menu term.
	 * @return array<string, mixed>
	 */
	public static function menu_to_array( WP_Term $menu ): array {
		return array(
			'id'          => (int) $menu->term_id,
			'description' => (string) $menu->description,
			'name'        => (string) $menu->name,
			'slug'        => (string) $menu->slug,
			'meta'        => self::build_term_meta_map( (int) $menu->term_id ),
			'locations'   => self::get_menu_locations( (int) $menu->term_id ),
			'auto_add'    => self::get_menu_auto_add( (int) $menu->term_id ),
		);
	}

	/**
	 * Map a nav_menu_item WP_Post into the abilities' canonical menu item array.
	 * Runs `wp_setup_nav_menu_item()` first so the derived fields (`url`,
	 * `type`, `type_label`, `object`, etc.) are populated.
	 *
	 * @param WP_Post $post The nav_menu_item post.
	 * @return array<string, mixed>
	 */
	public static function item_to_array( WP_Post $post ): array {
		$item = wp_setup_nav_menu_item( $post );

		$menus = 0;
		$terms = get_the_terms( $post, 'nav_menu' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$first = array_shift( $terms );
			if ( $first instanceof WP_Term ) {
				$menus = (int) $first->term_id;
			}
		}

		$title = isset( $item->title ) ? (string) $item->title : '';
		return array(
			'id'          => (int) $item->ID,
			'title'       => array(
				'raw'      => $title,
				'rendered' => (string) apply_filters( 'the_title', $title, $item->ID ),
			),
			'status'      => (string) $item->post_status,
			'url'         => (string) ( $item->url ?? '' ),
			'attr_title'  => (string) ( $item->attr_title ?? '' ),
			'description' => (string) ( $item->description ?? '' ),
			'type'        => (string) ( $item->type ?? 'custom' ),
			'type_label'  => (string) ( $item->type_label ?? '' ),
			'object'      => (string) ( $item->object ?? '' ),
			'object_id'   => absint( $item->object_id ?? 0 ),
			'parent'      => (int) ( $item->menu_item_parent ?? 0 ),
			'menu_order'  => (int) ( $item->menu_order ?? 0 ),
			'target'      => (string) ( $item->target ?? '' ),
			'classes'     => array_values( (array) ( $item->classes ?? array() ) ),
			'xfn'         => array_values( array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) ( $item->xfn ?? '' ) ) ) ) ),
			'invalid'     => (bool) ( $item->_invalid ?? false ),
			'meta'        => self::build_post_meta_map( (int) $item->ID ),
			'menus'       => $menus,
		);
	}

	/**
	 * Build the REST-equivalent meta map for a nav_menu term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>
	 */
	public static function build_term_meta_map( int $term_id ): array {
		if ( $term_id <= 0 || ! function_exists( 'get_registered_meta_keys' ) ) {
			return array();
		}
		$out  = array();
		$keys = get_registered_meta_keys( 'term' );
		foreach ( $keys as $key => $args ) {
			if ( empty( $args['show_in_rest'] ) ) {
				continue;
			}
			$single      = ! empty( $args['single'] );
			$out[ $key ] = get_term_meta( $term_id, $key, $single );
		}
		return $out;
	}

	/**
	 * Build the REST-equivalent meta map for a nav_menu_item post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function build_post_meta_map( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_registered_meta_keys' ) ) {
			return array();
		}
		$keys = get_registered_meta_keys( 'post', 'nav_menu_item' );
		if ( empty( $keys ) ) {
			$keys = get_registered_meta_keys( 'post' );
		}
		$out = array();
		foreach ( $keys as $key => $args ) {
			if ( empty( $args['show_in_rest'] ) ) {
				continue;
			}
			$single      = ! empty( $args['single'] );
			$out[ $key ] = get_post_meta( $post_id, $key, $single );
		}
		return $out;
	}

	/**
	 * Return the list of theme locations a menu is assigned to.
	 *
	 * @param int $menu_id Menu term id.
	 * @return array<int, string>
	 */
	public static function get_menu_locations( int $menu_id ): array {
		$locations = (array) get_nav_menu_locations();
		$assigned  = array();
		foreach ( $locations as $location => $term_id ) {
			if ( (int) $term_id === $menu_id ) {
				$assigned[] = (string) $location;
			}
		}
		return $assigned;
	}

	/**
	 * Return the list of post types this menu is auto-added to (from
	 * the `nav_menu_options.auto_add` option that the Menus UI manages).
	 *
	 * @param int $menu_id Menu term id.
	 * @return array<int, int>
	 */
	public static function get_menu_auto_add( int $menu_id ): array {
		$options  = (array) get_option( 'nav_menu_options', array() );
		$auto_add = isset( $options['auto_add'] ) && is_array( $options['auto_add'] ) ? array_map( 'intval', $options['auto_add'] ) : array();
		return in_array( $menu_id, $auto_add, true ) ? array( $menu_id ) : array();
	}

	/**
	 * Assign a menu to (or remove it from) a list of theme locations.
	 * `$locations` is the absolute list of locations the menu should belong
	 * to after the call — any other location currently pointing at this menu
	 * is cleared.
	 *
	 * @param int                $menu_id   Menu term id.
	 * @param array<int, string> $locations Theme location slugs.
	 */
	public static function set_menu_locations( int $menu_id, array $locations ): void {
		$current = (array) get_nav_menu_locations();
		foreach ( $current as $loc => $term_id ) {
			if ( (int) $term_id === $menu_id && ! in_array( $loc, $locations, true ) ) {
				unset( $current[ $loc ] );
			}
		}
		foreach ( $locations as $loc ) {
			$current[ $loc ] = $menu_id;
		}
		set_theme_mod( 'nav_menu_locations', $current );
	}

	/**
	 * Normalize a failure result into the abilities' error envelope.
	 *
	 * @param mixed  $result   Original return value (typically WP_Error or false).
	 * @param string $fallback Fallback message used when $result is not WP_Error.
	 * @return array{success: bool, message: string}
	 */
	public static function error_from( $result, string $fallback ): array {
		if ( $result instanceof WP_Error ) {
			$message = $result->get_error_message();
			return array(
				'success' => false,
				'message' => '' !== $message ? $message : $fallback,
			);
		}
		return array(
			'success' => false,
			'message' => $fallback,
		);
	}
}
