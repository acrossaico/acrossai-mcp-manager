<?php
/**
 * Feature 067 — import an Elementor template from a JSON export.
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

class Import_Template extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/import-template',
			'args' => array(
				'label'               => __( 'Import Elementor Template', 'acrossai-abilities-manager' ),
				'description'         => __( 'Import an Elementor template from a JSON export (as produced by export-template). Regenerates element IDs to avoid collisions.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'data'         => array( 'type' => 'object' ),
						'title'        => array( 'type' => 'string' ),
						'overwrite_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'   => array( 'data' ),
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
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$data         = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
		$title        = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : (string) ( $data['title'] ?? 'Imported Template' );
		$overwrite_id = absint( $input['overwrite_id'] ?? 0 );

		if ( empty( $data ) || ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return array( 'success' => false, 'message' => __( 'Invalid export payload — content array required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}
		$template_type = isset( $data['template_type'] ) ? sanitize_key( (string) $data['template_type'] ) : 'page';

		if ( $overwrite_id > 0 ) {
			$existing = get_post( $overwrite_id );
			if ( ! $existing instanceof \WP_Post || Template_Query::CPT !== $existing->post_type ) {
				return array( 'success' => false, 'message' => __( 'overwrite_id does not point to an Elementor template.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
			}
			$template_id = $overwrite_id;
			wp_update_post( array( 'ID' => $template_id, 'post_title' => $title ) );
		} else {
			$template_id = wp_insert_post( array(
				'post_title'  => $title,
				'post_type'   => Template_Query::CPT,
				'post_status' => 'publish',
			), true );
			if ( is_wp_error( $template_id ) ) {
				return array( 'success' => false, 'message' => (string) $template_id->get_error_message(), 'error_code' => (string) $template_id->get_error_code() );
			}
			$template_id = (int) $template_id;
		}

		wp_set_object_terms( $template_id, $template_type, Template_Query::TYPE_TAX, false );
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $template_id, '_elementor_template_type', $template_type );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $template_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		if ( isset( $data['sub_type'] ) && '' !== $data['sub_type'] ) {
			update_post_meta( $template_id, '_elementor_template_sub_type', (string) $data['sub_type'] );
		}
		if ( isset( $data['page_settings'] ) && is_array( $data['page_settings'] ) ) {
			update_post_meta( $template_id, '_elementor_page_settings', $data['page_settings'] );
		}
		if ( isset( $data['conditions'] ) && is_array( $data['conditions'] ) ) {
			update_post_meta( $template_id, '_elementor_conditions', $data['conditions'] );
		}

		// Regenerate element IDs to avoid collisions with existing content.
		$cloned = array();
		foreach ( $data['content'] as $element ) {
			if ( is_array( $element ) ) {
				$cloned[] = Document_Repository::reassign_subtree_ids( $element );
			}
		}
		Document_Repository::save_data( $template_id, $cloned, 'post' );

		return array(
			'success'     => true,
			'template_id' => $template_id,
			/* translators: %d: template id */
			'message'     => sprintf( __( 'Imported template as #%d.', 'acrossai-abilities-manager' ), $template_id ),
		);
	}
}
