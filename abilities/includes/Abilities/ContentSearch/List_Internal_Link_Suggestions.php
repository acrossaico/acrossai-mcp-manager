<?php
/**
 * Feature 055 — list internal-link suggestions.
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
 * List all suggestions currently in the store, optionally filtered by
 * post_id or status.
 */
class List_Internal_Link_Suggestions extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/list-internal-link-suggestions',
			'args' => array(
				'label'               => __( 'List Internal Link Suggestions', 'acrossai-abilities-manager' ),
				'description'         => __( 'List all suggestions in the option-backed store, optionally filtered by post_id and/or status (pending / approved / rejected / applied).', 'acrossai-abilities-manager' ),
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
							'minimum' => 0,
						),
						'status'  => array(
							'type' => 'string',
							'enum' => array( 'pending', 'approved', 'rejected', 'applied' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'items'   => array( 'type' => 'array' ),
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
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : null;
		if ( null !== $post_id && $post_id <= 0 ) {
			$post_id = null;
		}
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : null;
		if ( null !== $status && ! in_array( $status, array( 'pending', 'approved', 'rejected', 'applied' ), true ) ) {
			$status = null;
		}
		$items  = Suggestion_Store::list( $post_id, $status );
		return array(
			'success' => true,
			'items'   => $items,
			/* translators: %d: count */
			'message' => sprintf( __( '%d suggestion(s) matched the filter.', 'acrossai-abilities-manager' ), count( $items ) ),
		);
	}
}
