<?php
/**
 * Feature 055 — chunk-level content search fallback.
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
 * Split search-matching post content into paragraph-length chunks and
 * return the ones that contain the query substring. Fallback implementation
 * — no persistent chunk table.
 */
class Search_Content_Chunks extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/search-content-chunks',
			'args' => array(
				'label'               => __( 'Search Content Chunks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Split search-matching post content into paragraph-length chunks and return the ones that contain the query substring. Fallback implementation — no persistent chunk table. Backed by the same WP_Query `s=` search as content-search-items.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'    => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 10,
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'chunks'  => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'search',
						'sub_group_label' => __( 'Search', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
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
		$query    = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		$per_page = max( 1, min( 50, (int) ( $input['per_page'] ?? 10 ) ) );
		if ( '' === $query ) {
			return array(
				'success' => false,
				'message' => __( 'query is required.', 'acrossai-abilities-manager' ),
			);
		}

		$wp_query = new \WP_Query(
			array(
				's'              => $query,
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
			)
		);

		$chunks         = array();
		$needle         = strtolower( $query );
		$per_post       = 3;   // Return at most 3 matching chunks per post.
		$max_chunk_len  = 500; // Hardened: hard-cap each chunk at 500 chars.
		foreach ( $wp_query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			// Hardened per-post cap: skip items the caller cannot read.
			if ( ! current_user_can( 'read_post', (int) $post->ID ) ) {
				continue;
			}
			$plain      = wp_strip_all_tags( (string) $post->post_content );
			$paragraphs = preg_split( '/\r?\n{2,}/', $plain ) ?: array();
			$hits       = 0;
			foreach ( $paragraphs as $idx => $para ) {
				if ( false === stripos( $para, $needle ) ) {
					continue;
				}
				$text = trim( $para );
				if ( strlen( $text ) > $max_chunk_len ) {
					$text = rtrim( substr( $text, 0, $max_chunk_len ) ) . '...';
				}
				$chunks[] = array(
					'post_id'     => (int) $post->ID,
					'chunk_index' => (int) $idx,
					'text'        => $text,
					'score'       => 1.0,
				);
				++$hits;
				if ( $hits >= $per_post ) {
					break;
				}
			}
		}

		return array(
			'success' => true,
			'chunks'  => $chunks,
			/* translators: %d: chunk count */
			'message' => sprintf( __( 'Returned %d matching chunk(s).', 'acrossai-abilities-manager' ), count( $chunks ) ),
		);
	}
}
