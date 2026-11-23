<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Media
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Media;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Media ability class (absorbed).
 */
class Update_Media extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'media/update-media',
			'args' => array(
				'label'               => __( 'Update Media', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update an attachment\'s title, caption, description, or alt text via POST /wp/v2/media/{id}.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-media',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'title'       => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'alt_text'    => array( 'type' => 'string' ),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'media'   => array( 'type' => 'object' ),
						'updated' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
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

		$post = get_post( $id );
		if ( ! ( $post instanceof \WP_Post ) || 'attachment' !== $post->post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Attachment not found.', 'acrossai-abilities-manager' ),
			);
		}

		$post_data  = array( 'ID' => $id );
		$updated    = array();
		$has_change = false;
		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = (string) $input['title'];
			$updated[]               = 'title';
			$has_change              = true;
		}
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = (string) $input['caption'];
			$updated[]                 = 'caption';
			$has_change                = true;
		}
		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = (string) $input['description'];
			$updated[]                 = 'description';
			$has_change                = true;
		}

		if ( $has_change ) {
			$result = wp_update_post( Slash_Input::slash( $post_data, $input ), true );
			if ( is_wp_error( $result ) || 0 === $result ) {
				return Media_Formatter::error_from(
					$result,
					/* translators: %d: attachment ID */
					sprintf( __( 'Could not update attachment #%d.', 'acrossai-abilities-manager' ), $id )
				);
			}
		}

		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', Slash_Input::slash( (string) $input['alt_text'], $input ) );
			$updated[] = 'alt_text';
		}

		$refreshed = get_post( $id );
		return array(
			'success' => true,
			'media'   => $refreshed instanceof \WP_Post ? Media_Formatter::to_array( $refreshed ) : array(),
			'updated' => $updated,
			/* translators: %d: attachment ID */
			'message' => sprintf( __( 'Updated attachment #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
