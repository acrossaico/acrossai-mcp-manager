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
 * Create_Comment ability class (absorbed).
 */
class Create_Comment extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'comments/create-comment',
			'args' => array(
				'label'               => __( 'Create Comment', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a comment via POST /wp/v2/comments. Requires post and content.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-comments',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post'         => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'content'      => array( 'type' => 'string' ),
						'author'       => array( 'type' => 'integer' ),
						'author_name'  => array( 'type' => 'string' ),
						'author_email' => array( 'type' => 'string' ),
						'author_url'   => array( 'type' => 'string' ),
						'parent'       => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'status'       => array( 'type' => 'string' ),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'post', 'content' ),
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
						'idempotent'  => false,
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
		$post_id = isset( $input['post'] ) ? (int) $input['post'] : 0;
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		if ( $post_id <= 0 || '' === $content ) {
			return array(
				'success' => false,
				'message' => __( 'post and content are required.', 'acrossai-abilities-manager' ),
			);
		}

		$data = array(
			'comment_post_ID'  => $post_id,
			'comment_content'  => $content,
			'comment_parent'   => isset( $input['parent'] ) ? (int) $input['parent'] : 0,
			'comment_approved' => self::map_input_status( isset( $input['status'] ) ? (string) $input['status'] : 'approved' ),
		);

		if ( isset( $input['author'] ) ) {
			$data['user_id'] = (int) $input['author'];
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

		$new_id = wp_insert_comment( Slash_Input::slash( $data, $input ) );
		if ( ! $new_id ) {
			return Comment_Formatter::error_from( false, __( 'Could not create comment.', 'acrossai-abilities-manager' ) );
		}

		$comment = get_comment( (int) $new_id );
		if ( null === $comment ) {
			return array(
				'success' => false,
				'message' => __( 'Comment created but could not be retrieved.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success' => true,
			'comment' => Comment_Formatter::to_array( $comment ),
			'message' => __( 'Comment created.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Translate the public status vocabulary into the raw `comment_approved` value
	 * that wp_insert_comment expects (1 / 0 / 'spam'). Defaults to approved.
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
			case '':
			case 'approved':
			case 'approve':
			case '1':
			default:
				return '1';
		}
	}
}
