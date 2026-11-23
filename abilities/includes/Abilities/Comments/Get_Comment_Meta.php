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
 * Get_Comment_Meta ability class (absorbed).
 */
class Get_Comment_Meta extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'comments/get-comment-meta',
			'args' => array(
				'label'               => __( 'Get Comment Meta', 'acrossai-abilities-manager' ),
				'description'         => __( 'Fetch the REST-exposed meta map for a comment via GET /wp/v2/comments/{id} (only keys registered with register_meta show_in_rest=true).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-comments',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'key' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'meta'    => array( 'type' => array( 'object', 'string', 'array', 'integer', 'boolean', 'null' ) ),
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
		$id  = (int) ( $input['id'] ?? 0 );
		$key = (string) ( $input['key'] ?? '' );
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

		$meta_map = Comment_Formatter::build_meta_map( $id );

		if ( '' !== $key ) {
			return array(
				'success' => true,
				'meta'    => array_key_exists( $key, $meta_map ) ? $meta_map[ $key ] : null,
			);
		}

		return array(
			'success' => true,
			'meta'    => $meta_map,
		);
	}
}
