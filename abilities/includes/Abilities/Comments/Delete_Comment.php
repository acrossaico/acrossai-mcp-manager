<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
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
 * Delete_Comment ability class (absorbed).
 */
class Delete_Comment extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'comments/delete-comment',
			'args' => array(
				'label'               => __( 'Delete Comment', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a comment via DELETE /wp/v2/comments/{id}. Defaults to trash; pass force=true to delete permanently.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-comments',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'deleted' => array( 'type' => 'boolean' ),
						'comment' => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'manage',
						'sub_group_label' => __( 'Manage', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
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
		$id    = (int) ( $input['id'] ?? 0 );
		$force = ! empty( $input['force'] );
		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A valid id is required.', 'acrossai-abilities-manager' ),
			);
		}

		$comment = get_comment( $id );
		if ( null === $comment ) {
			return array(
				'success' => false,
				'message' => __( 'Comment not found.', 'acrossai-abilities-manager' ),
			);
		}

		$snapshot = Comment_Formatter::to_array( $comment );

		$deleted = wp_delete_comment( $id, $force );
		if ( ! $deleted ) {
			return Comment_Formatter::error_from(
				false,
				/* translators: %d: comment ID */
				sprintf( __( 'Could not delete comment #%d.', 'acrossai-abilities-manager' ), $id )
			);
		}

		return array(
			'success' => true,
			'deleted' => true,
			'comment' => $snapshot,
			'message' => $force
				/* translators: %d: comment ID */
				? sprintf( __( 'Permanently deleted comment #%d.', 'acrossai-abilities-manager' ), $id )
				/* translators: %d: comment ID */
				: sprintf( __( 'Trashed comment #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
