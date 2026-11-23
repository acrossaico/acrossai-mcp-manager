<?php
/**
 * Feature 067 — inspect frontend render context for a post.
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

/**
 * Inspect the frontend wrapper/render context for a post separately
 * from Elementor content quality — template, canvas type, header/
 * footer presence, wrapper classes.
 */
class Evaluate_Render_Context extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/evaluate-render-context',
			'args' => array(
				'label'               => __( 'Evaluate Elementor Render Context', 'acrossai-abilities-manager' ),
				'description'         => __( 'Inspect the frontend wrapper and render context for a post: template file, canvas type (default / elementor_canvas / elementor_header_footer), and Elementor edit-mode flag.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'template'      => array( 'type' => 'string' ),
						'canvas_type'   => array( 'type' => 'string' ),
						'edit_mode'     => array( 'type' => 'string' ),
						'template_type' => array( 'type' => 'string' ),
						'message'       => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-guidance',
						'sub_group_label' => __( 'Discovery & Guidance', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'post_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return array( 'success' => false, 'post_id' => 0, 'message' => __( 'post_id is required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}
		if ( ! get_post( $post_id ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => __( 'Post not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}

		$template      = (string) get_post_meta( $post_id, '_wp_page_template', true );
		$edit_mode     = (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
		$template_type = (string) get_post_meta( $post_id, '_elementor_template_type', true );

		$canvas_type = 'default';
		if ( 'elementor_canvas' === $template ) {
			$canvas_type = 'canvas';
		} elseif ( 'elementor_header_footer' === $template ) {
			$canvas_type = 'header_footer';
		}

		return array(
			'success'       => true,
			'post_id'       => $post_id,
			'template'      => $template,
			'canvas_type'   => $canvas_type,
			'edit_mode'     => $edit_mode,
			'template_type' => $template_type,
			/* translators: %d: post id */
			'message'       => sprintf( __( 'Evaluated render context for post #%d.', 'acrossai-abilities-manager' ), $post_id ),
		);
	}
}
