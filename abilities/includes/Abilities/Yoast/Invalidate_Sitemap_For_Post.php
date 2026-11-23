<?php
/**
 * Feature 106 — Invalidate Sitemap For One Post.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Sitemap_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/invalidate-sitemap-for-post — Invalidate Sitemap For One Post.
 */
final class Invalidate_Sitemap_For_Post extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/invalidate-sitemap-for-post';
	}

	protected function ability_label(): string {
		return __( 'Invalidate Sitemap For One Post', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Clear the sitemap cache entry covering one post, rather than the whole sitemap. The targeted call after a single edit, so a large site does not rebuild every sitemap page.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-sitemap';
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_id',
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),
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
		$post_id = (int) $input['post_id'];
		$done    = Sitemap_Repository::invalidate_post( $post_id );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		return array(
			'post_id' => $post_id,
			'message' => sprintf( /* translators: %d: post id */ __( 'Sitemap cache cleared for post %d.', 'acrossai-abilities-manager' ), $post_id ),
		);
	}
}
