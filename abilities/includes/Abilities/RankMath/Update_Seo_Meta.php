<?php
/**
 * Feature 069 — write Rank Math SEO meta for one post.
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
 * Ability #45 — rank-math/update-seo-meta.
 *
 * The only per-post SEO write in the suite: Rank Math core's own abilities read a
 * post's resolved meta but cannot change it.
 *
 * Named -update-seo-meta rather than -update-post-meta so it does not read as a
 * variant of the plugin's generic content/update-post-meta — which can technically
 * write these keys but will happily store the wrong shape, since robots must be an
 * array and the content flags must be the literal 'on' or absent.
 *
 * Post-scoped, so the floor is edit_posts plus a per-object edit_post check.
 */
class Update_Seo_Meta extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'update-seo-meta';
	}

	protected function ability_label(): string {
		return __( 'Update Rank Math SEO Meta', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Write Rank Math SEO metadata for one post: title, meta description, focus keyword, robots directives, canonical URL and the pillar/cornerstone flags. Values are encoded the way Rank Math expects — robots as a list, flags as presence rather than a boolean — which a generic post-meta writer would get wrong. Read the current values with Rank Math\'s own rank-math/get-post-seo-meta ability.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content';
	}

	protected function rank_math_cap(): string {
		return 'onpage_general';
	}

	protected function input_properties(): array {
		return array(
			'post_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
			'title'          => array( 'type' => 'string', 'description' => __( 'SEO title. May contain Rank Math template variables. Empty string clears the override.', 'acrossai-abilities-manager' ) ),
			'description'    => array( 'type' => 'string', 'description' => __( 'Meta description. Empty string clears the override.', 'acrossai-abilities-manager' ) ),
			'focus_keyword'  => array( 'type' => 'string', 'description' => __( 'Comma-separated focus keywords; the first is the primary.', 'acrossai-abilities-manager' ) ),
			'canonical_url'  => array( 'type' => 'string', 'description' => __( 'Canonical URL override. Empty string clears it.', 'acrossai-abilities-manager' ) ),
			'robots'         => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ),
				),
				'description' => __( 'Robots directives as a list. Rank Math stores this as an array, so a string would be misread.', 'acrossai-abilities-manager' ),
			),
			'is_pillar'      => array( 'type' => 'boolean', 'description' => __( 'Mark as pillar content.', 'acrossai-abilities-manager' ) ),
			'is_cornerstone' => array( 'type' => 'boolean', 'description' => __( 'Mark as cornerstone content.', 'acrossai-abilities-manager' ) ),
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),
			'updated' => array( 'type' => 'object' ),
		);
	}

	protected function required_input(): array {
		return array( 'post_id' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		$fields = $input;
		unset( $fields['post_id'] );

		$result = Post_Meta_Repository::update_seo_meta( $post_id, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of fields written, 2: post id */
			_n( 'Wrote %1$d SEO field to post %2$d.', 'Wrote %1$d SEO fields to post %2$d.', count( $result['updated'] ), 'acrossai-abilities-manager' ),
			count( $result['updated'] ),
			$post_id
		);

		return $result;
	}
}
