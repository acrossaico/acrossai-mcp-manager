<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Fonts
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Fonts;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a font face under a given font family. Force=true is always sent —
 * font CPTs do not support trash.
 *
 * WordPress core does not expose dedicated wp_*_font_face functions —
 * everything goes through the REST controller, so this ability does too.
 */
class Delete_Font_Face extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'fonts/delete-font-face',
			'args' => array(
				'label'               => __( 'Delete Font Face', 'acrossai-abilities-manager' ),
				'description'         => __( 'Permanently delete a Font Library font face under a given font family. Trash is not supported for font CPTs — deletion is immediate.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-fonts',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'font_family_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Parent font family post ID.', 'acrossai-abilities-manager' ),
						),
						'id'             => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Font face post ID.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'font_family_id', 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'deleted' => array( 'type' => 'boolean' ),
						'face'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'font-faces',
						'sub_group_label' => __( 'Font Faces', 'acrossai-abilities-manager' ),
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
		$family_id = (int) ( $input['font_family_id'] ?? 0 );
		$face_id   = (int) ( $input['id'] ?? 0 );
		if ( $family_id <= 0 || $face_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Both font_family_id and id are required.', 'acrossai-abilities-manager' ),
			);
		}

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/font-families/' . $family_id . '/font-faces/' . $face_id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			return array(
				'success' => false,
				'message' => $error->get_error_message(),
			);
		}

		$data = (array) $response->get_data();

		return array(
			'success' => true,
			'deleted' => ! empty( $data['deleted'] ),
			'face'    => isset( $data['previous'] ) ? (array) $data['previous'] : array(),
			/* translators: %d: font face ID */
			'message' => sprintf( __( 'Deleted font face #%d.', 'acrossai-abilities-manager' ), $face_id ),
		);
	}
}
