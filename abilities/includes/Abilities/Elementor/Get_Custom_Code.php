<?php
/**
 * Feature 067 — read one Elementor Pro custom code snippet.
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

class Get_Custom_Code extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/get-custom-code',
			'args' => array(
				'label'               => __( 'Get Elementor Pro Custom Code', 'acrossai-abilities-manager' ),
				'description'         => __( 'Read a single Elementor Pro Custom Code snippet including its code body. Requires Elementor Pro.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array( 'snippet_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'required' => array( 'snippet_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'snippet'    => array( 'type' => 'object' ),
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
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
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
		$snippet = array_merge(
			List_Custom_Code::to_summary( $post ),
			array( 'code' => (string) $post->post_content )
		);
		return array(
			'success' => true,
			'snippet' => $snippet,
			/* translators: %d: snippet id */
			'message' => sprintf( __( 'Returned Pro custom code snippet #%d.', 'acrossai-abilities-manager' ), $snippet_id ),
		);
	}
}
