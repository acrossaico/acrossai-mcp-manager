<?php
/**
 * Feature 067 — update an Elementor Pro custom code snippet.
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

class Update_Custom_Code extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/update-custom-code',
			'args' => array(
				'label'               => __( 'Update Elementor Pro Custom Code', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update fields on an existing Elementor Pro Custom Code snippet. Requires Elementor Pro.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'snippet_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'title'      => array( 'type' => 'string' ),
						'code'       => array( 'type' => 'string' ),
						'location'   => array( 'type' => 'string', 'enum' => array( 'head', 'body_start', 'body_end', 'footer' ) ),
						'priority'   => array( 'type' => 'integer' ),
						'status'     => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ) ),
					),
					'required' => array( 'snippet_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'snippet_id' => array( 'type' => 'integer' ),
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
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
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
		$post = get_post( $snippet_id );
		if ( ! $post instanceof \WP_Post || List_Custom_Code::CPT !== $post->post_type ) {
			return array( 'success' => false, 'message' => __( 'Snippet not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}
		$post_update = array( 'ID' => $snippet_id );
		if ( isset( $input['title'] ) ) {
			$post_update['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['code'] ) ) {
			$post_update['post_content'] = (string) $input['code'];
		}
		if ( isset( $input['status'] ) ) {
			$post_update['post_status'] = sanitize_key( (string) $input['status'] );
		}
		if ( count( $post_update ) > 1 ) {
			wp_update_post( $post_update );
		}
		if ( isset( $input['location'] ) ) {
			update_post_meta( $snippet_id, '_elementor_snippet_location', sanitize_key( (string) $input['location'] ) );
		}
		if ( isset( $input['priority'] ) ) {
			update_post_meta( $snippet_id, '_elementor_snippet_priority', (int) $input['priority'] );
		}
		return array(
			'success'    => true,
			'snippet_id' => $snippet_id,
			/* translators: %d: id */
			'message'    => sprintf( __( 'Updated Pro custom code snippet #%d.', 'acrossai-abilities-manager' ), $snippet_id ),
		);
	}
}
