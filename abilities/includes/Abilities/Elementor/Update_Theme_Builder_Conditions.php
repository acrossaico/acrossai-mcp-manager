<?php
/**
 * Feature 067 — write Elementor Theme Builder display conditions.
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
 * Replace the display conditions attached to a template
 * (_elementor_conditions post meta). Pass an empty array to clear.
 */
class Update_Theme_Builder_Conditions extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/update-theme-builder-conditions',
			'args' => array(
				'label'               => __( 'Update Theme Builder Conditions', 'acrossai-abilities-manager' ),
				'description'         => __( 'Replace the display conditions attached to an Elementor template. Pass an empty array to clear all conditions.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'template_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'conditions' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'type'     => array( 'type' => 'string', 'enum' => array( 'include', 'exclude' ) ),
									'name'     => array( 'type' => 'string' ),
									'sub_name' => array( 'type' => 'string' ),
									'sub_id'   => array( 'type' => 'string' ),
								),
							),
						),
					),
					'required'             => array( 'template_id', 'conditions' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'     => array( 'type' => 'boolean' ),
						'template_id' => array( 'type' => 'integer' ),
						'conditions'  => array( 'type' => 'array' ),
						'message'     => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-templates',
						'sub_group_label' => __( 'Templates & Theme Builder', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
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
			return array( 'success' => false, 'template_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$template_id = absint( $input['template_id'] ?? 0 );
		$conditions  = isset( $input['conditions'] ) && is_array( $input['conditions'] ) ? array_values( $input['conditions'] ) : array();

		if ( $template_id <= 0 ) {
			return array( 'success' => false, 'template_id' => 0, 'message' => __( 'template_id is required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}
		$post = get_post( $template_id );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'success' => false, 'template_id' => $template_id, 'message' => __( 'Template not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}

		if ( empty( $conditions ) ) {
			delete_post_meta( $template_id, '_elementor_conditions' );
		} else {
			update_post_meta( $template_id, '_elementor_conditions', $conditions );
		}

		// Invalidate Elementor's condition cache so the new rules take effect.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$instance = \Elementor\Plugin::$instance;
			if ( isset( $instance->files_manager ) && method_exists( $instance->files_manager, 'clear_cache' ) ) {
				$instance->files_manager->clear_cache();
			}
		}

		return array(
			'success'     => true,
			'template_id' => $template_id,
			'conditions'  => $conditions,
			/* translators: 1: count, 2: template id */
			'message'     => sprintf( __( 'Updated to %1$d conditions on template #%2$d.', 'acrossai-abilities-manager' ), count( $conditions ), $template_id ),
		);
	}
}
