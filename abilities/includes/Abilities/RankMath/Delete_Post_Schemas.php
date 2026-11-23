<?php
/**
 * Feature 069 — delete all schema data for a post.
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
 * Ability #35 — rank-math/delete-post-schemas.
 *
 * Deletes the whole schema set including the type index, which the plugin's generic
 * content/delete-post-meta cannot do in one call — it would leave the index behind
 * pointing at removed data.
 */
class Delete_Post_Schemas extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'delete-post-schemas';
	}

	protected function ability_label(): string {
		return __( 'Delete Rank Math Post Schemas', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Remove every Rank Math schema attached to a post, including the type index, so no orphaned references remain. The markup cannot be recovered and the post will emit no structured data until new schema is added. Inspect what is there first with Rank Math\'s rank-math/get-post-schema ability.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-schema';
	}

	protected function rank_math_cap(): string {
		return 'onpage_snippet';
	}

	/**
	 * Rank Math's own schema route requires this module; matching it means the ability
	 * reports rank_math_module_inactive rather than writing schema that will not render.
	 */
	protected function required_module(): string {
		return 'rich-snippet';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),
			'deleted' => array( 'type' => 'integer' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'post_id' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Post_Meta_Repository::delete_schemas(
			isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = 0 === $result['deleted']
			? sprintf(
				/* translators: %d: post id */
				__( 'Post %d had no schema to remove.', 'acrossai-abilities-manager' ),
				$result['post_id']
			)
			: sprintf(
				/* translators: 1: number of schemas removed, 2: post id */
				_n( 'Removed %1$d schema from post %2$d.', 'Removed %1$d schemas from post %2$d.', $result['deleted'], 'acrossai-abilities-manager' ),
				$result['deleted'],
				$result['post_id']
			);

		return $result;
	}
}
