<?php
/**
 * Feature 055 — apply an approved internal-link suggestion.
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
 * Apply an approved suggestion by inserting an `<a>` around the first
 * occurrence of the suggestion's anchor text in the target post's content.
 * Marks the suggestion `applied` on success.
 */
class Apply_Internal_Link_Suggestion extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/apply-internal-link-suggestion',
			'args' => array(
				'label'               => __( 'Apply Internal Link Suggestion', 'acrossai-abilities-manager' ),
				'description'         => __( 'Apply an approved suggestion by wrapping the first case-insensitive occurrence of the suggestion\'s anchor text in the target post\'s content with an <a href> tag. Marks the suggestion `applied` on success. Requires manage_options + edit_others_posts.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_others_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'suggestion_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'suggestion_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
						'applied' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'internal-links',
						'sub_group_label' => __( 'Internal Links', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
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
		$id = absint( $input['suggestion_id'] ?? 0 );
		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'suggestion_id is required.', 'acrossai-abilities-manager' ),
			);
		}
		$s = Suggestion_Store::get( $id );
		if ( null === $s ) {
			return array(
				'success' => false,
				'message' => __( 'Suggestion not found.', 'acrossai-abilities-manager' ),
			);
		}
		if ( 'approved' !== (string) $s['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Only approved suggestions can be applied.', 'acrossai-abilities-manager' ),
			);
		}
		$post_id = absint( $s['post_id'] );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'success' => false,
				'message' => __( 'Target post not found.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 055 hardening — per-target-post cap check + internal-CPT filter.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to edit this target post.', 'acrossai-abilities-manager' ),
			);
		}
		$post_type_obj = get_post_type_object( (string) $post->post_type );
		if ( ! $post_type_obj instanceof \WP_Post_Type
			|| in_array( $post_type_obj->name, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true )
			|| ! ( (bool) $post_type_obj->public || (bool) $post_type_obj->show_ui || (bool) $post_type_obj->show_in_rest ) ) {
			return array(
				'success' => false,
				'message' => __( 'This post type is not editable through this ability.', 'acrossai-abilities-manager' ),
			);
		}

		$site_host = wp_parse_url( (string) home_url( '/' ), PHP_URL_HOST );
		$url_host  = wp_parse_url( (string) $s['target_url'], PHP_URL_HOST );
		if ( null === $url_host || $url_host !== $site_host ) {
			return array(
				'success' => false,
				'message' => __( 'Suggestion target must resolve to a same-site URL.', 'acrossai-abilities-manager' ),
			);
		}

		$anchor  = (string) $s['anchor_text'];
		$content = (string) $post->post_content;
		if ( '' === $anchor || false === stripos( $content, $anchor ) ) {
			return array(
				'success' => false,
				'message' => __( 'Anchor text not found in post content.', 'acrossai-abilities-manager' ),
			);
		}

		$replacement = sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) $s['target_url'] ),
			esc_html( $anchor )
		);
		$pos      = stripos( $content, $anchor );
		$new_body = substr( $content, 0, $pos ) . $replacement . substr( $content, $pos + strlen( $anchor ) );

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_body,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return array(
				'success' => false,
				'message' => $updated->get_error_message(),
			);
		}

		Suggestion_Store::update_status( $id, 'applied' );

		return array(
			'success' => true,
			'post_id' => $post_id,
			'applied' => true,
			/* translators: 1: suggestion id, 2: post ID */
			'message' => sprintf( __( 'Applied suggestion #%1$d to post #%2$d.', 'acrossai-abilities-manager' ), $id, $post_id ),
		);
	}
}
