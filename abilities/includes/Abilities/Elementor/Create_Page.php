<?php
/**
 * Feature 067 — create a new post/page pre-configured for Elementor.
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
 * Insert a new post/page and configure it as an Elementor document
 * (sets _elementor_edit_mode, _elementor_template_type, _elementor_version).
 */
class Create_Page extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/create-page',
			'args' => array(
				'label'               => __( 'Create Elementor Page', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new post or page with Elementor builder mode enabled. Returns the new post ID and edit URL.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'         => array( 'type' => 'string' ),
						'post_type'     => array( 'type' => 'string', 'default' => 'page' ),
						'status'        => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private' ), 'default' => 'draft' ),
						'template'      => array( 'type' => 'string' ),
						'page_settings' => array( 'type' => 'object' ),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'post_id'  => array( 'type' => 'integer' ),
						'edit_url' => array( 'type' => 'string' ),
						'message'  => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-documents',
						'sub_group_label' => __( 'Documents', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
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
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}

		$title         = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$post_type     = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'page';
		$status        = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
		$template      = isset( $input['template'] ) ? sanitize_text_field( (string) $input['template'] ) : '';
		$page_settings = isset( $input['page_settings'] ) && is_array( $input['page_settings'] ) ? $input['page_settings'] : array();

		if ( '' === $title ) {
			return array( 'success' => false, 'message' => __( 'title is required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => $post_type,
				'post_status' => $status,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return array( 'success' => false, 'message' => (string) $post_id->get_error_message(), 'error_code' => (string) $post_id->get_error_code() );
		}
		$post_id = (int) $post_id;

		// Configure the post for Elementor authoring.
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		$template_type = 'page' === $post_type ? 'wp-page' : 'wp-post';
		update_post_meta( $post_id, '_elementor_template_type', $template_type );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		// Seed _elementor_data with an empty array so the editor treats the doc as Elementor.
		Document_Repository::save_data( $post_id, array(), 'none' );
		if ( '' !== $template ) {
			update_post_meta( $post_id, '_wp_page_template', $template );
		}
		if ( ! empty( $page_settings ) ) {
			update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
		}

		$edit_url = admin_url( sprintf( 'post.php?post=%d&action=elementor', $post_id ) );

		return array(
			'success'  => true,
			'post_id'  => $post_id,
			'edit_url' => $edit_url,
			/* translators: 1: post type, 2: post id */
			'message'  => sprintf( __( 'Created Elementor %1$s #%2$d.', 'acrossai-abilities-manager' ), $post_type, $post_id ),
		);
	}
}
