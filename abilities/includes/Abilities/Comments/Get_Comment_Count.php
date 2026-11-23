<?php
/**
 * Site introspection read — aggregated comment counts (Feature 063).
 *
 * Returns the per-status comment counters (approved, moderated, spam,
 * trash, post-trashed) plus total_comments for the whole site or a
 * specific post id.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Comments
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Comments;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Get_Comment_Count ability class.
 */
class Get_Comment_Count extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'comments/get-comment-count',
			'args' => array(
				'label'               => __( 'Get Comment Count', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return per-status comment counters (approved, moderated, spam, trash, post-trashed) and total_comments. Optionally scoped to a single post id.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-comments',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => 0,
							'description' => __( 'Post id to scope the counts to; 0 (default) returns site-wide counts.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'counts'  => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'introspection',
						'sub_group_label' => __( 'Introspection', 'acrossai-abilities-manager' ),
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		$counts = wp_count_comments( (int) $post_id );

		return array(
			'success' => true,
			'counts'  => (object) $counts,
			'message' => __( 'Comment counts fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
