<?php
/**
 * Feature 067 — delete an Elementor Pro custom code snippet.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

class Delete_Custom_Code extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/delete-custom-code',
			'args' => array(
				'label'               => __( 'Delete Elementor Pro Custom Code', 'acrossai-abilities-manager' ),
				'description'         => __( 'Trash (default) or permanently delete an Elementor Pro Custom Code snippet. Requires Elementor Pro.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'snippet_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'force'      => array( 'type' => 'boolean', 'default' => false ),
					),
					'required' => array( 'snippet_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'snippet_id' => array( 'type' => 'integer' ),
						'action'     => array( 'type' => 'string' ),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-custom-code', 'sub_group_label' => __( 'Custom Code', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$pro = Document_Repository::assert_elementor_pro_available();
		if ( is_wp_error( $pro ) ) {
			return array( 'success' => false, 'message' => (string) $pro->get_error_message(), 'error_code' => (string) $pro->get_error_code() );
		}
		$snippet_id = absint( $input['snippet_id'] ?? 0 );
		$force      = ! empty( $input['force'] );
		$post = get_post( $snippet_id );
		if ( ! $post instanceof \WP_Post || List_Custom_Code::CPT !== $post->post_type ) {
			return array( 'success' => false, 'message' => __( 'Snippet not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}
		$result = $force ? wp_delete_post( $snippet_id, true ) : wp_trash_post( $snippet_id );
		if ( ! $result ) {
			return array( 'success' => false, 'snippet_id' => $snippet_id, 'message' => __( 'Failed to delete snippet.', 'acrossai-abilities-manager' ), 'error_code' => 'delete_failed' );
		}
		return array(
			'success'    => true,
			'snippet_id' => $snippet_id,
			'action'     => $force ? 'deleted' : 'trashed',
			/* translators: 1: action, 2: id */
			'message'    => sprintf( __( '%1$s Pro custom code snippet #%2$d.', 'acrossai-abilities-manager' ), $force ? 'Deleted' : 'Trashed', $snippet_id ),
		);
	}
}
