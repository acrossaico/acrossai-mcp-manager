<?php
/**
 * Feature 055 — extract on-site internal links from a post's content.
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
 * Parse a post's content for `<a href>` tags whose target resolves to a
 * same-site URL. Returns each match with anchor text + resolved post id
 * (if any).
 */
class Find_Internal_Links extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/find-internal-links',
			'args' => array(
				'label'               => __( 'Find Internal Links', 'acrossai-abilities-manager' ),
				'description'         => __( 'Parse a post\'s rendered content for <a href> tags whose target resolves to a same-site URL. Returns each match with anchor text + resolved target post id (via url_to_postid()) when available.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'links'   => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'find',
						'sub_group_label' => __( 'Find', 'acrossai-abilities-manager' ),
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
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A valid post_id is required.', 'acrossai-abilities-manager' ),
			);
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'acrossai-abilities-manager' ),
			);
		}
		// Feature 055 hardening — caller must be able to read the source post
		// (avoids leaking private-post outbound link inventories).
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to read this post.', 'acrossai-abilities-manager' ),
			);
		}

		$site_host = wp_parse_url( (string) home_url( '/' ), PHP_URL_HOST );
		// Feature 055 hardening — parse raw post_content (do NOT run the
		// full the_content filter chain, which triggers shortcode + oEmbed
		// side effects with the current user context).
		$content = (string) $post->post_content;

		$links       = array();
		$max_anchor  = 200; // Hard-cap anchor text length.
		if ( preg_match_all( '/<a\s+[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$href = esc_url_raw( (string) $m[2] );
				$text = wp_strip_all_tags( (string) $m[3] );
				if ( strlen( $text ) > $max_anchor ) {
					$text = rtrim( substr( $text, 0, $max_anchor ) ) . '...';
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( '' === $href || null === $host || $host !== $site_host ) {
					continue;
				}
				$target_id = url_to_postid( $href );
				$links[]   = array(
					'target_url' => $href,
					'target_id'  => (int) $target_id,
					'anchor'     => sanitize_text_field( $text ),
				);
			}
		}

		return array(
			'success' => true,
			'links'   => $links,
			/* translators: 1: link count, 2: post ID */
			'message' => sprintf( __( 'Found %1$d internal link(s) in post #%2$d.', 'acrossai-abilities-manager' ), count( $links ), $post_id ),
		);
	}
}
