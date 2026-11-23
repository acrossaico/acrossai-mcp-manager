<?php
/**
 * Feature 069 — set or clear the Rank Math primary term.
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
 * Ability #47 — rank-math/update-primary-term.
 *
 * Refuses a term the post does not have, rather than storing a value Rank Math will
 * silently ignore — which would look like success while changing nothing.
 */
class Update_Primary_Term extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'update-primary-term';
	}

	protected function ability_label(): string {
		return __( 'Update Rank Math Primary Term', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Set or clear the Rank Math primary term for a post and taxonomy, which drives the %category% permalink and breadcrumb trail. Pass term_id 0 to clear it. A term the post is not assigned to is refused, because Rank Math would ignore it and the write would appear to succeed while changing nothing.', 'acrossai-abilities-manager' );
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
			'taxonomy' => array( 'type' => 'string' ),
			'term_id'  => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Term to make primary, or 0 to clear. Must be a term the post already has.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id'      => array( 'type' => 'integer' ),
			'taxonomy'     => array( 'type' => 'string' ),
			'primary_term' => array( 'type' => array( 'object', 'null' ) ),
			'cleared'      => array( 'type' => 'boolean' ),
		);
	}

	protected function required_input(): array {
		return array( 'post_id', 'taxonomy', 'term_id' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Post_Meta_Repository::update_primary_term(
			isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0,
			isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '',
			isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = $result['cleared']
			? sprintf(
				/* translators: 1: taxonomy name, 2: post id */
				__( 'Cleared the primary %1$s for post %2$d.', 'acrossai-abilities-manager' ),
				$result['taxonomy'],
				$result['post_id']
			)
			: sprintf(
				/* translators: 1: taxonomy name, 2: post id */
				__( 'Set the primary %1$s for post %2$d.', 'acrossai-abilities-manager' ),
				$result['taxonomy'],
				$result['post_id']
			);

		return $result;
	}
}
