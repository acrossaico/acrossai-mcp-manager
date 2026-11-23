<?php
/**
 * Feature 106 — Get Primary Term.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Term_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * taxonomies/get-primary-term — Get Primary Term.
 */
final class Get_Primary_Term extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'taxonomies/get-primary-term';
	}

	protected function ability_label(): string {
		return __( 'Get Primary Term', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Which term Yoast treats as a post\'s primary one in a taxonomy. The primary term decides the breadcrumb trail and the canonical category in permalinks, so a post in several categories can breadcrumb through the wrong one without anything looking broken.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-terms';
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post ID.', 'acrossai-abilities-manager' ),
			),

			'taxonomy' => array(
				'type'        => 'string',
				'description' => __( 'Taxonomy name, e.g. category.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_id',
			'taxonomy',
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),

			'taxonomy' => array( 'type' => 'string' ),

			'term_id' => array( 'type' => 'integer' ),

			'term_name' => array( 'type' => 'string' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$post_id  = (int) $input['post_id'];
		$taxonomy = (string) $input['taxonomy'];

		if ( ! get_post( $post_id ) ) {
			return new WP_Error(
				'unknown_post',
				sprintf( /* translators: %d: post id */ __( 'No post with id %d.', 'acrossai-abilities-manager' ), $post_id )
			);
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'unknown_taxonomy',
				sprintf( /* translators: %s: taxonomy */ __( 'No taxonomy named "%s".', 'acrossai-abilities-manager' ), $taxonomy )
			);
		}

		$term_id = Term_Repository::primary_term( $post_id, $taxonomy );
		$term    = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;

		return array(
			'post_id'   => $post_id,
			'taxonomy'  => $taxonomy,
			'term_id'   => $term_id,
			'term_name' => ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : '',
			'message'   => $term_id > 0
				? __( 'Primary term resolved.', 'acrossai-abilities-manager' )
				: __( 'No primary term is set; Yoast will pick one itself.', 'acrossai-abilities-manager' ),
		);
	}
}
