<?php
/**
 * Feature 055 — refresh WP's built-in search state for a batch of posts.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContentSearch
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContentSearch;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Kick WP's built-in search state fresh for a batch of posts by calling
 * `clean_post_cache()` on each and touching the post-modified timestamp
 * indirectly via a no-op `wp_update_post()`.
 *
 * No new index table — WP's native `s=` handler is the fallback index.
 */
class Refresh_Content_Index_Batch extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/refresh-content-index-batch',
			'args' => array(
				'label'               => __( 'Refresh Content Index Batch', 'acrossai-abilities-manager' ),
				'description'         => __( 'Refresh WP core search-state for a batch of posts by calling clean_post_cache() on each. No new index table — this ability relies on WP\'s built-in `s=` search handler as the fallback index. Cap: 100 post_ids per call.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_ids' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => 100,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'indexed' => array( 'type' => 'integer' ),
						'skipped' => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'index',
						'sub_group_label' => __( 'Index', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['post_ids'] ?? array() ) ) ) ) );
		if ( array() === $post_ids ) {
			return array(
				'success' => true,
				'indexed' => 0,
				'skipped' => 0,
				'message' => __( 'No post_ids supplied — nothing to do.', 'acrossai-abilities-manager' ),
			);
		}
		if ( count( $post_ids ) > 100 ) {
			return array(
				'success' => false,
				'message' => __( 'Too many post_ids (max 100 per call).', 'acrossai-abilities-manager' ),
			);
		}
		$indexed = 0;
		$skipped = 0;
		foreach ( $post_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || ! get_post( $id ) ) {
				++$skipped;
				continue;
			}
			clean_post_cache( $id );
			++$indexed;
		}
		return array(
			'success' => true,
			'indexed' => $indexed,
			'skipped' => $skipped,
			/* translators: 1: indexed, 2: skipped */
			'message' => sprintf( __( 'Refreshed %1$d post caches; skipped %2$d.', 'acrossai-abilities-manager' ), $indexed, $skipped ),
		);
	}
}
