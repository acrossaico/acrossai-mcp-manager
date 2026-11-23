<?php
/**
 * Feature 067 — duplicate an Elementor template.
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

class Duplicate_Template extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/duplicate-template',
			'args' => array(
				'label'               => __( 'Duplicate Elementor Template', 'acrossai-abilities-manager' ),
				'description'         => __( 'Duplicate a saved Elementor template with fresh element IDs, preserving type + conditions + sub_type.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'title'       => array( 'type' => 'string' ),
					),
					'required'   => array( 'template_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'               => array( 'type' => 'boolean' ),
						'source_template_id'    => array( 'type' => 'integer' ),
						'duplicate_template_id' => array( 'type' => 'integer' ),
						'message'               => array( 'type' => 'string' ),
						'error_code'            => array( 'type' => 'string' ),
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
		$src_id = absint( $input['template_id'] ?? 0 );
		$title  = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$src    = get_post( $src_id );
		if ( ! $src instanceof \WP_Post || Template_Query::CPT !== $src->post_type ) {
			return array( 'success' => false, 'message' => __( 'Source template not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}
		if ( '' === $title ) {
			$title = $src->post_title . ' — copy';
		}

		$dup_id = wp_insert_post( array(
			'post_title'  => $title,
			'post_type'   => Template_Query::CPT,
			'post_status' => (string) $src->post_status,
		), true );
		if ( is_wp_error( $dup_id ) ) {
			return array( 'success' => false, 'message' => (string) $dup_id->get_error_message(), 'error_code' => (string) $dup_id->get_error_code() );
		}
		$dup_id = (int) $dup_id;

		// Copy taxonomy term.
		$terms = wp_get_object_terms( $src_id, Template_Query::TYPE_TAX, array( 'fields' => 'slugs' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			wp_set_object_terms( $dup_id, $terms, Template_Query::TYPE_TAX, false );
		}

		// Copy critical meta and data with fresh element IDs.
		foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_template_sub_type', '_elementor_version', '_elementor_page_settings', '_elementor_conditions' ) as $key ) {
			$val = get_post_meta( $src_id, $key, true );
			if ( '' !== $val ) {
				update_post_meta( $dup_id, $key, $val );
			}
		}
		$src_data = Document_Repository::decode_data( Document_Repository::get_raw_data( $src_id ) );
		$cloned   = array();
		foreach ( $src_data as $element ) {
			if ( is_array( $element ) ) {
				$cloned[] = Document_Repository::reassign_subtree_ids( $element );
			}
		}
		Document_Repository::save_data( $dup_id, $cloned, 'none' );

		return array(
			'success'               => true,
			'source_template_id'    => $src_id,
			'duplicate_template_id' => $dup_id,
			/* translators: 1: source, 2: dup */
			'message'               => sprintf( __( 'Duplicated template #%1$d as #%2$d.', 'acrossai-abilities-manager' ), $src_id, $dup_id ),
		);
	}
}
