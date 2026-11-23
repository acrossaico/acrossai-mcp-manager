<?php
/**
 * Feature 069 — read the Rank Math primary term.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Post_Meta_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #46 — rank-math/get-primary-term.
 *
 * Read-only, idempotent. Returns the assigned terms alongside the primary, because
 * setting a primary term Rank Math will ignore is the common mistake and the assigned
 * list is what prevents it.
 */
class Get_Primary_Term extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-primary-term';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Primary Term', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return the Rank Math primary term for a post and taxonomy, plus every term the post has in that taxonomy with the primary flagged. The primary term drives the %category% permalink and breadcrumb trail, and Rank Math ignores a primary term the post does not actually have — so use the assigned list to pick a valid one.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content';
	}

	protected function rank_math_cap(): string {
		return 'onpage_general';
	}

	protected function input_properties(): array {
		return array(
			'post_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
			'taxonomy' => array( 'type' => 'string', 'description' => __( 'Taxonomy name, e.g. category or product_cat.', 'acrossai-abilities-manager' ) ),
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id'      => array( 'type' => 'integer' ),
			'taxonomy'     => array( 'type' => 'string' ),
			'primary_term' => array( 'type' => array( 'object', 'null' ) ),
			'assigned'     => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array( 'post_id', 'taxonomy' );
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Post_Meta_Repository::get_primary_term(
			isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0,
			isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : ''
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = null === $result['primary_term']
			? sprintf(
				/* translators: 1: taxonomy name, 2: post id */
				__( 'No primary %1$s is set for post %2$d.', 'acrossai-abilities-manager' ),
				$result['taxonomy'],
				$result['post_id']
			)
			: sprintf(
				/* translators: 1: term name, 2: taxonomy name, 3: post id */
				__( '"%1$s" is the primary %2$s for post %3$d.', 'acrossai-abilities-manager' ),
				$result['primary_term']['name'],
				$result['taxonomy'],
				$result['post_id']
			);

		return $result;
	}
}
