<?php
/**
 * Feature 067 — create a new Elementor template.
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

class Create_Template extends Ability_Definition {

	private const VALID_TYPES = array( 'page', 'section', 'popup', 'header', 'footer', 'single', 'archive', 'kit', 'container' );

	protected function ability(): array {
		return array(
			'name' => 'elementor/create-template',
			'args' => array(
				'label'               => __( 'Create Elementor Template', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new Elementor template. Supports type = page | section | popup | header | footer | single | archive.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'          => array( 'type' => 'string' ),
						'type'           => array( 'type' => 'string', 'enum' => self::VALID_TYPES ),
						'status'         => array( 'type' => 'string', 'default' => 'publish' ),
						'data'           => array( 'type' => 'array' ),
						'conditions'     => array( 'type' => 'array' ),
						'popup_settings' => array( 'type' => 'object' ),
					),
					'required'   => array( 'title', 'type' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'template_id' => array( 'type' => 'integer' ),
						'edit_url'    => array( 'type' => 'string' ),
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
		$title  = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$type   = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : '';
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'publish';
		$data   = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
		$conds  = isset( $input['conditions'] ) && is_array( $input['conditions'] ) ? $input['conditions'] : array();
		$popup  = isset( $input['popup_settings'] ) && is_array( $input['popup_settings'] ) ? $input['popup_settings'] : array();

		if ( '' === $title || ! in_array( $type, self::VALID_TYPES, true ) ) {
			return array( 'success' => false, 'message' => __( 'title and a valid type are required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_template_type' );
		}

		$template_id = wp_insert_post( array(
			'post_title'  => $title,
			'post_type'   => Template_Query::CPT,
			'post_status' => $status,
		), true );
		if ( is_wp_error( $template_id ) ) {
			return array( 'success' => false, 'message' => (string) $template_id->get_error_message(), 'error_code' => (string) $template_id->get_error_code() );
		}
		$template_id = (int) $template_id;

		wp_set_object_terms( $template_id, $type, Template_Query::TYPE_TAX, false );
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $template_id, '_elementor_template_type', $type );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $template_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		Document_Repository::save_data( $template_id, $data, 'none' );
		if ( ! empty( $conds ) ) {
			update_post_meta( $template_id, '_elementor_conditions', $conds );
		}
		if ( ! empty( $popup ) && 'popup' === $type ) {
			update_post_meta( $template_id, '_elementor_page_settings', $popup );
		}

		return array(
			'success'     => true,
			'template_id' => $template_id,
			'edit_url'    => admin_url( sprintf( 'post.php?post=%d&action=elementor', $template_id ) ),
			/* translators: 1: type, 2: id */
			'message'     => sprintf( __( 'Created %1$s template #%2$d.', 'acrossai-abilities-manager' ), $type, $template_id ),
		);
	}
}
