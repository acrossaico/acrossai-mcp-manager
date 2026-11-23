<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Taxonomies
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Taxonomies;

use WP_Error;
use WP_Taxonomy;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors WP_REST_Terms_Controller / WP_REST_Taxonomies_Controller output
 * (edit context, no _links) so Term / Taxonomy abilities can drop their
 * rest_do_request() calls without breaking the public output shape.
 */
final class Term_Formatter {

	/**
	 * Map a WP_Term into the abilities' canonical term array.
	 *
	 * @param WP_Term $term Term to format.
	 * @return array<string, mixed>
	 */
	public static function term_to_array( WP_Term $term ): array {
		$tax = get_taxonomy( $term->taxonomy );
		$out = array(
			'id'          => (int) $term->term_id,
			'count'       => (int) $term->count,
			'description' => (string) $term->description,
			'link'        => (string) get_term_link( $term ),
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'taxonomy'    => (string) $term->taxonomy,
			'meta'        => self::build_term_meta_map( (int) $term->term_id ),
		);
		if ( $tax && ! empty( $tax->hierarchical ) ) {
			$out['parent'] = (int) $term->parent;
		}
		return $out;
	}

	/**
	 * Map a WP_Taxonomy into the abilities' canonical taxonomy array.
	 *
	 * @param WP_Taxonomy $tax Taxonomy object.
	 * @return array<string, mixed>
	 */
	public static function taxonomy_to_array( WP_Taxonomy $tax ): array {
		return array(
			'name'           => (string) $tax->label,
			'slug'           => (string) $tax->name,
			'description'    => (string) $tax->description,
			'types'          => array_values( (array) $tax->object_type ),
			'hierarchical'   => (bool) $tax->hierarchical,
			'rest_base'      => ! empty( $tax->rest_base ) ? (string) $tax->rest_base : (string) $tax->name,
			'rest_namespace' => ! empty( $tax->rest_namespace ) ? (string) $tax->rest_namespace : 'wp/v2',
			'labels'         => (array) get_taxonomy_labels( $tax ),
			'visibility'     => array(
				'public'             => (bool) $tax->public,
				'publicly_queryable' => (bool) $tax->publicly_queryable,
				'show_admin_column'  => (bool) $tax->show_admin_column,
				'show_in_nav_menus'  => (bool) $tax->show_in_nav_menus,
				'show_in_quick_edit' => (bool) $tax->show_in_quick_edit,
				'show_ui'            => (bool) $tax->show_ui,
			),
		);
	}

	/**
	 * Build the REST-equivalent meta map for a term — only registered keys
	 * with show_in_rest=true, honoring each key's `single` flag.
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
