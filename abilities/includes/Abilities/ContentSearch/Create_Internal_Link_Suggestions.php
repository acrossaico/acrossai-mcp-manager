<?php
/**
 * Feature 055 — create internal-link suggestions.
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
 * Persist one or more internal-link suggestions for a given post via
 * `Suggestion_Store`.
 */
class Create_Internal_Link_Suggestions extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/create-internal-link-suggestions',
			'args' => array(
				'label'               => __( 'Create Internal Link Suggestions', 'acrossai-abilities-manager' ),
				'description'         => __( 'Persist one or more internal-link suggestions for a given post via the option-backed suggestion store. Each suggestion carries a target URL + proposed anchor text. Store cap: 500 suggestions total.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'suggestions' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 20,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'target_url'  => array( 'type' => 'string' ),
									'anchor_text' => array( 'type' => 'string' ),
									'notes'       => array( 'type' => 'string' ),
								),
								'required'             => array( 'target_url', 'anchor_text' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'post_id', 'suggestions' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'created_ids'    => array( 'type' => 'array' ),
						'rejected_items' => array( 'type' => 'array' ),
						'message'        => array( 'type' => 'string' ),
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
		$post_id     = (int) ( $input['post_id'] ?? 0 );
		$suggestions = (array) ( $input['suggestions'] ?? array() );
		if ( $post_id <= 0 || array() === $suggestions ) {
			return array(
				'success' => false,
				'message' => __( 'post_id and a non-empty suggestions array are required.', 'acrossai-abilities-manager' ),
			);
		}
		if ( ! get_post( $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 055 hardening — reject suggestions whose target URL is
		// off-site at ingest time (defence in depth on top of the
		// apply-time re-check).
		$site_host = wp_parse_url( (string) home_url( '/' ), PHP_URL_HOST );

		$created  = array();
		$rejected = array();
		foreach ( $suggestions as $idx => $s ) {
			if ( ! is_array( $s ) ) {
				$rejected[] = array(
					'index'  => $idx,
					'reason' => 'not-an-object',
				);
				continue;
			}
			$target_url = esc_url_raw( (string) ( $s['target_url'] ?? '' ) );
			$host       = wp_parse_url( $target_url, PHP_URL_HOST );
			if ( '' === $target_url || null === $host || $host !== $site_host ) {
				$rejected[] = array(
					'index'  => $idx,
					'reason' => 'target-not-same-site',
				);
				continue;
			}
			$id = Suggestion_Store::insert(
				array(
					'post_id'     => $post_id,
					'target_url'  => $target_url,
					'anchor_text' => sanitize_text_field( (string) ( $s['anchor_text'] ?? '' ) ),
					'notes'       => sanitize_text_field( (string) ( $s['notes'] ?? '' ) ),
				)
			);
			if ( $id > 0 ) {
				$created[] = $id;
			} else {
				$rejected[] = array(
					'index'  => $idx,
					'reason' => 'store-cap-reached',
				);
			}
		}

		return array(
			'success'        => array() !== $created,
			'created_ids'    => $created,
			'rejected_items' => $rejected,
			/* translators: 1: created, 2: rejected */
			'message'        => sprintf( __( 'Created %1$d suggestion(s); %2$d rejected.', 'acrossai-abilities-manager' ), count( $created ), count( $rejected ) ),
		);
	}
}
