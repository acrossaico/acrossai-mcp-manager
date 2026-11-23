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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Comment ability class (absorbed).
 */
class Update_Comment extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'comments/update-comment',
			'args' => array(
				'label'               => __( 'Update Comment', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update a comment via POST /wp/v2/comments/{id}. Only the supplied fields are touched.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-comments',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'           => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'content'      => array( 'type' => 'string' ),
						'status'       => array( 'type' => 'string' ),
						'author_name'  => array( 'type' => 'string' ),
						'author_email' => array( 'type' => 'string' ),
						'author_url'   => array( 'type' => 'string' ),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
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
						'destructive' => false,
						'idempotent'  => true,
					),
					'input_flags'  => Slash_Input::meta_flags(),
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
		$id = (int) ( $input['id'] ?? 0 );
		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A valid id is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( null === get_comment( $id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Comment not found.', 'acrossai-abilities-manager' ),
			);
		}

		$data = array( 'comment_ID' => $id );
		if ( isset( $input['content'] ) ) {
			$data['comment_content'] = (string) $input['content'];
		}
		if ( isset( $input['status'] ) ) {
			$data['comment_approved'] = self::map_input_status( (string) $input['status'] );
		}
		if ( isset( $input['author_name'] ) ) {
			$data['comment_author'] = (string) $input['author_name'];
		}
		if ( isset( $input['author_email'] ) ) {
			$data['comment_author_email'] = (string) $input['author_email'];
		}
		if ( isset( $input['author_url'] ) ) {
			$data['comment_author_url'] = (string) $input['author_url'];
		}

		if ( 1 === count( $data ) ) {
			return array(
				'success' => false,
				'message' => __( 'At least one field to update is required.', 'acrossai-abilities-manager' ),
			);
		}

		$result = wp_update_comment( Slash_Input::slash( $data, $input ), true );
		if ( is_wp_error( $result ) || false === $result ) {
			return Comment_Formatter::error_from(
				$result,
				/* translators: %d: comment ID */
				sprintf( __( 'Could not update comment #%d.', 'acrossai-abilities-manager' ), $id )
			);
		}

		$updated = get_comment( $id );
		return array(
			'success' => true,
			'comment' => null !== $updated ? Comment_Formatter::to_array( $updated ) : array(),
			/* translators: %d: comment ID */
			'message' => sprintf( __( 'Updated comment #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}

	/**
	 * Translate the public status vocabulary into the raw `comment_approved`
	 * value expected by wp_update_comment.
	 */
	private static function map_input_status( string $status ): string {
		switch ( $status ) {
			case 'hold':
			case 'unapproved':
			case '0':
				return '0';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			case 'approved':
			case 'approve':
			case '1':
			default:
				return '1';
		}
	}
}
