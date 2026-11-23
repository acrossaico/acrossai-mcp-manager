<?php
/**
 * Feature 106 — Set Primary Term.
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
 * taxonomies/set-primary-term — Set Primary Term.
 */
final class Set_Primary_Term extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'taxonomies/set-primary-term';
	}

	protected function ability_label(): string {
		return __( 'Set Primary Term', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Set which term Yoast treats as a post\'s primary one in a taxonomy, controlling its breadcrumb trail and the category used in permalinks. The term must already be assigned to the post — setting one that is not produces a breadcrumb to a category the post is not in.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-terms';
	}

	protected function suggested_abilities(): array {
		return array(
			'taxonomies/get-primary-term',
		);
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
				'description' => __( 'Taxonomy name.', 'acrossai-abilities-manager' ),
			),

			'term_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Term ID, which must already be assigned to the post.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_id',
			'taxonomy',
			'term_id',
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),

			'term_id' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$post_id  = (int) $input['post_id'];
		$taxonomy = (string) $input['taxonomy'];
		$term_id  = (int) $input['term_id'];

		if ( ! get_post( $post_id ) ) {
			return new WP_Error(
				'unknown_post',
				sprintf( /* translators: %d: post id */ __( 'No post with id %d.', 'acrossai-abilities-manager' ), $post_id )
			);
		}

		// The term must be ON the post, not merely exist: Yoast will happily store a primary term the
		// post is not assigned to, and the breadcrumb then points somewhere the post does not live.
		if ( ! has_term( $term_id, $taxonomy, $post_id ) ) {
			return new WP_Error(
				'term_not_assigned',
				sprintf(
					/* translators: 1: term id, 2: post id, 3: taxonomy */
					__( 'Term %1$d is not assigned to post %2$d in %3$s. Assign it first, or the breadcrumb will point at a term the post is not in.', 'acrossai-abilities-manager' ),
					$term_id,
					$post_id,
					$taxonomy
				)
			);
		}

		Term_Repository::set_primary_term( $post_id, $taxonomy, $term_id );

		return array(
			'post_id' => $post_id,
			'term_id' => $term_id,
			'message' => __( 'Primary term set.', 'acrossai-abilities-manager' ),
		);
	}
}
