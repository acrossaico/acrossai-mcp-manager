<?php
/**
 * Feature 132 — write AIOSEO SEO fields across many posts.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Post_Seo_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/bulk-update-post-seo.
 *
 * AIOSEO's `aioseo-posts/seo-data-update` writes one post. This writes the same fields across a named
 * list, through AIOSEO's own service so the per-post edit permission and patch semantics are
 * identical — see {@see Post_Seo_Repository} for why that delegation is load-bearing rather than
 * tidy.
 *
 * The obvious use is the one the single-post ability makes tedious: noindex a batch, set a shared
 * focus keyphrase across a cluster, or clear a canonical URL that was applied too widely.
 *
 * Confirm-gated. Not because a single write is dangerous — it is not, and the single-post ability is
 * not gated either — but because the batch is where a mistaken field reaches a hundred posts in one
 * call with no per-post prompt in between.
 *
 * @since 0.0.44
 */
class Bulk_Update_Post_Seo extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'bulk-update-post-seo';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Bulk Update All in One SEO Post Fields', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Write the same All in One SEO fields across a list of posts in one call: SEO title, meta description, canonical URL, focus and additional keyphrases, robots directives, the pillar-content flag and social meta. AIOSEO\'s own aioseo-posts/seo-data-update takes one post at a time; this is the batch. Each post is written through AIOSEO\'s own post service, so a post you cannot edit is refused individually rather than silently written, and a field you did not name keeps its value. Every post reports its own outcome and one failure does not abort the rest. Robots directives need care AIOSEO does not take: a post ignores every per-post directive while its \"use default\" switch is on, so passing noindex alone would be stored and have no effect — this turns that switch off for you and says so in notes. Schema and EEAT cannot be written — AIOSEO refuses those on its own ability too. Find the posts to act on with aioseo-posts/list-missing-seo or aioseo-posts/list-truseo-score.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-content';
	}

	/**
	 * AIOSEO gates post writes on `aioseo_page_general_settings`, and its service re-checks that plus
	 * `edit_post` per post. Declared here so the ability is not even listed for a role AIOSEO would
	 * refuse on every post.
	 *
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return 'aioseo_page_general_settings';
	}

	/**
	 * @since  0.0.44
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'Pass confirm: true. This writes the same fields to every post in the list, and there is no per-post prompt.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'post_ids' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'minItems'    => 1,
				'description' => __( 'Post ids to write, in any order.', 'acrossai-abilities-manager' ),
			),
			'fields'   => array(
				'type'        => 'object',
				'description' => __( 'Field name => value, applied to every post. Accepted: title, description, canonical_url, pillar_content, focus_keyphrase, additional_keyphrases, robots, social. Pass null for title or description to clear it and inherit the post type\'s template.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'results' => array( 'type' => 'array' ),
			'updated' => array( 'type' => 'integer' ),
			'failed'  => array( 'type' => 'integer' ),
			'fields'  => array( 'type' => 'array' ),
			'notes'   => array( 'type' => 'array' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'post_ids', 'fields' );
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$post_ids = $input['post_ids'] ?? array();
		$fields   = $input['fields'] ?? array();

		if ( ! is_array( $post_ids ) || array() === $post_ids ) {
			return new WP_Error(
				'invalid_input',
				__( 'post_ids must be a non-empty list of post ids.', 'acrossai-abilities-manager' )
			);
		}

		if ( ! is_array( $fields ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'fields must be an object of field name => value.', 'acrossai-abilities-manager' )
			);
		}

		return Post_Seo_Repository::bulk_update( $post_ids, $fields );
	}
}
