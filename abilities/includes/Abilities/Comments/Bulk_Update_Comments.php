<?php
/**
 * Feature 055 — batched moderation action across many comments.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Comments
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Comments;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Apply the same status change to up to 100 comments in one call.
 *
 * Wraps wp_set_comment_status(). Enforces the same `moderate_comments`
 * capability WP core checks in the comment moderation UI. Returns a
 * per-comment success/failure envelope.
 */
class Bulk_Update_Comments extends Ability_Definition {

	/**
	 * Maximum number of comment ids per single call.
	 */
	private const MAX_IDS = 100;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'comments/bulk-update-comments',
			'args' => array(
				'label'               => __( 'Bulk Update Comments', 'acrossai-abilities-manager' ),
				'description'         => __( 'Apply the same status change (approve / hold / spam / trash) to up to 100 comments in one call. Enforces manage_options + moderate_comments. Returns per-comment success/failure entries.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-comments',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'moderate_comments' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'comment_ids' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'minItems' => 1,
							'maxItems' => self::MAX_IDS,
						),
						'status'      => array(
							'type' => 'string',
							'enum' => array( 'approve', 'hold', 'spam', 'trash' ),
						),
					),
					'required'             => array( 'comment_ids', 'status' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'   => array( 'type' => 'boolean' ),
						'status'    => array( 'type' => 'string' ),
						'succeeded' => array( 'type' => 'array' ),
						'failed'    => array( 'type' => 'array' ),
						'message'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'moderation',
						'sub_group_label' => __( 'Moderation', 'acrossai-abilities-manager' ),
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
		$status      = sanitize_key( (string) ( $input['status'] ?? '' ) );
		$comment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['comment_ids'] ?? array() ) ) ) ) );

		// Feature 055 hardening — accept the same aliases WP core admins are used to.
		$status = match ( $status ) {
			'approved'   => 'approve',
			'pending', 'unapproved', 'unapprove' => 'hold',
			default      => $status,
		};

		if ( '' === $status || ! in_array( $status, array( 'approve', 'hold', 'spam', 'trash' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'A valid status is required (approve / hold / spam / trash).', 'acrossai-abilities-manager' ),
			);
		}

		if ( array() === $comment_ids ) {
			return array(
				'success' => false,
				'message' => __( 'At least one comment_id is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( count( $comment_ids ) > self::MAX_IDS ) {
			return array(
				'success' => false,
				/* translators: %d: max comment ids per call */
				'message' => sprintf( __( 'Too many comment_ids (max %d per call).', 'acrossai-abilities-manager' ), self::MAX_IDS ),
			);
		}

		$succeeded = array();
		$failed    = array();

		foreach ( $comment_ids as $id ) {
			$id = absint( $id );
			if ( $id <= 0 ) {
				$failed[] = array(
					'id'      => $id,
					'message' => __( 'Invalid comment_id.', 'acrossai-abilities-manager' ),
				);
				continue;
			}
			$comment = get_comment( $id );
			if ( ! $comment instanceof \WP_Comment ) {
				$failed[] = array(
					'id'      => $id,
					'message' => __( 'Comment not found.', 'acrossai-abilities-manager' ),
				);
				continue;
			}
			// Feature 055 hardening — per-comment cap check on top of the
			// role-level `moderate_comments` gate. Also require `edit_post`
			// on the parent post so a mod cannot flip comments on a post
			// they cannot edit.
			if ( ! current_user_can( 'edit_comment', $id ) && ! current_user_can( 'edit_post', (int) $comment->comment_post_ID ) ) {
				$failed[] = array(
					'id'      => $id,
					'message' => __( 'You do not have permission to moderate this comment.', 'acrossai-abilities-manager' ),
				);
				continue;
			}
			$result = wp_set_comment_status( $id, $status, true );
			if ( is_wp_error( $result ) || false === $result ) {
				$failed[] = array(
					'id'      => $id,
					'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'wp_set_comment_status returned false.', 'acrossai-abilities-manager' ),
				);
				continue;
			}
			$succeeded[] = $id;
		}

		return array(
			'success'   => array() === $failed,
			'status'    => $status,
			'succeeded' => $succeeded,
			'failed'    => $failed,
			/* translators: 1: succeeded count, 2: failed count */
			'message'   => sprintf( __( 'Updated %1$d comments; %2$d failed.', 'acrossai-abilities-manager' ), count( $succeeded ), count( $failed ) ),
		);
	}
}
