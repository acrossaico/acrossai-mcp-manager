<?php
/**
 * Feature 055 — audit internal-link health across a batch of posts.
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
 * Scan up to N published posts for internal `<a href>` URLs and report
 * broken ones (targets that resolve on-site but return no post_id, or
 * targets whose resolved post is not published).
 *
 * Fallback implementation — makes no outbound HTTP requests. Broken
 * external links are out of scope.
 */
class Audit_Internal_Links extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/audit-internal-links',
			'args' => array(
				'label'               => __( 'Audit Internal Links', 'acrossai-abilities-manager' ),
				'description'         => __( 'Scan up to N published posts for internal <a href> URLs and report broken ones. Broken = same-site URL that resolves to no post_id, or resolves to a post that is not in `publish` status. Makes no outbound HTTP requests; external-link health is out of scope.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'broken'  => array( 'type' => 'array' ),
						'ok'      => array( 'type' => 'integer' ),
						'checked' => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'audit',
						'sub_group_label' => __( 'Audit', 'acrossai-abilities-manager' ),
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
		$per_page  = max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) );
		$site_host = wp_parse_url( (string) home_url( '/' ), PHP_URL_HOST );

		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$broken  = array();
		$ok      = 0;
		$checked = 0;
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			// Hardened per-post cap: skip items the caller cannot read.
			if ( ! current_user_can( 'read_post', (int) $post->ID ) ) {
				continue;
			}
			// Feature 055 hardening — parse raw content, not the full
			// the_content filter chain.
			$content = (string) $post->post_content;
			if ( ! preg_match_all( '/<a\s+[^>]*href=(["\'])(.*?)\1[^>]*>/is', $content, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $m ) {
				$href = esc_url_raw( (string) $m[2] );
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( '' === $href || null === $host || $host !== $site_host ) {
					continue;
				}
				++$checked;
				$target_id = url_to_postid( $href );
				if ( 0 === $target_id ) {
					$broken[] = array(
						'post_id'    => (int) $post->ID,
						'target_url' => $href,
						'reason'     => 'unresolved',
					);
					continue;
				}
				$target = get_post( $target_id );
				if ( ! $target instanceof \WP_Post || 'publish' !== $target->post_status ) {
					$broken[] = array(
						'post_id'    => (int) $post->ID,
						'target_url' => $href,
						'reason'     => 'not-published',
					);
					continue;
				}
				++$ok;
			}
		}

		return array(
			'success' => true,
			'broken'  => $broken,
			'ok'      => $ok,
			'checked' => $checked,
			/* translators: 1: broken count, 2: total checked */
			'message' => sprintf( __( 'Audited %2$d internal link(s); %1$d broken.', 'acrossai-abilities-manager' ), count( $broken ), $checked ),
		);
	}
}
