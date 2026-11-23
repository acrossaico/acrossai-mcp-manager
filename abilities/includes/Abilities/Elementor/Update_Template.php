<?php
/**
 * Feature 067 — update an existing Elementor template.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Template_Query;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

class Update_Template extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/update-template',
			'args' => array(
				'label'               => __( 'Update Elementor Template', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update an Elementor template — change title, page_settings, or replace the full data tree. force_replace=true required for destructive full-data overwrites.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
						'title'          => array( 'type' => 'string' ),
						'data'           => array( 'type' => 'array' ),
						'page_settings'  => array( 'type' => 'object' ),
						'force_replace'  => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'template_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'template_id' => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-templates', 'sub_group_label' => __( 'Templates & Theme Builder', 'acrossai-abilities-manager' ) ),
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
		$template_id   = absint( $input['template_id'] ?? 0 );
		$title         = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : null;
		$data          = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : null;
		$page_settings = isset( $input['page_settings'] ) && is_array( $input['page_settings'] ) ? $input['page_settings'] : null;
		$force_replace = ! empty( $input['force_replace'] );

		$post = get_post( $template_id );
		if ( ! $post instanceof \WP_Post || Template_Query::CPT !== $post->post_type ) {
			return array( 'success' => false, 'message' => __( 'Template not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}

		if ( null !== $data ) {
			$existing = Document_Repository::decode_data( Document_Repository::get_raw_data( $template_id ) );
			if ( ! $force_replace && Document_Repository::is_document_populated( $existing ) && count( $data ) < count( $existing ) ) {
				return array( 'success' => false, 'template_id' => $template_id, 'message' => __( 'Data payload smaller than existing. Pass force_replace=true.', 'acrossai-abilities-manager' ), 'error_code' => 'force_replace_required' );
			}
			Document_Repository::save_data( $template_id, $data, 'post' );
		}
		if ( null !== $title ) {
			wp_update_post( array( 'ID' => $template_id, 'post_title' => $title ) );
		}
		if ( null !== $page_settings ) {
			update_post_meta( $template_id, '_elementor_page_settings', $page_settings );
		}

		return array(
			'success'     => true,
			'template_id' => $template_id,
			/* translators: %d: template id */
			'message'     => sprintf( __( 'Updated template #%d.', 'acrossai-abilities-manager' ), $template_id ),
		);
	}
}
