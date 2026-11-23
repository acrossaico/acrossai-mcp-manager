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
 * Update_Comment_Meta ability class (absorbed).
 */
class Update_Comment_Meta extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'comments/update-comment-meta',
			'args' => array(
				'label'               => __( 'Update Comment Meta', 'acrossai-abilities-manager' ),
				'description'         => __( 'Write meta values on a comment via POST /wp/v2/comments/{id} with a meta object. Only keys registered with register_meta show_in_rest=true accept writes.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-comments',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'meta' => array(
							'type'        => 'object',
							'description' => __( 'Object of meta keys → values to write.', 'acrossai-abilities-manager' ),
						),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'id', 'meta' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'meta'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'meta',
						'sub_group_label' => __( 'Meta', 'acrossai-abilities-manager' ),
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
		$id   = (int) ( $input['id'] ?? 0 );
		$meta = isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array();
		if ( $id <= 0 || empty( $meta ) ) {
			return array(
				'success' => false,
				'message' => __( 'id and a non-empty meta object are required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( null === get_comment( $id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Comment not found.', 'acrossai-abilities-manager' ),
			);
		}

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( ! Comment_Formatter::is_meta_key_writable( $key ) ) {
				continue;
			}
			$sanitized = sanitize_meta( $key, $value, 'comment' );
			update_comment_meta( $id, $key, Slash_Input::slash( $sanitized, $input ) );
		}

		return array(
			'success' => true,
			'meta'    => Comment_Formatter::build_meta_map( $id ),
			/* translators: %d: comment ID */
			'message' => sprintf( __( 'Wrote meta on comment #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
