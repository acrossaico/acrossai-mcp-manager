<?php
/**
 * Feature 067 — read a single Elementor template.
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

class Get_Template extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/get-template',
			'args' => array(
				'label'               => __( 'Get Elementor Template', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return a single Elementor template with metadata, conditions, and optional _elementor_data payload.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
						'include_data' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'template_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'template' => array( 'type' => 'object' ),
						'message'  => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-templates', 'sub_group_label' => __( 'Templates & Theme Builder', 'acrossai-abilities-manager' ) ),
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
		$template_id  = absint( $input['template_id'] ?? 0 );
		$include_data = ! empty( $input['include_data'] );
		$post = get_post( $template_id );
		if ( ! $post instanceof \WP_Post || Template_Query::CPT !== $post->post_type ) {
			return array( 'success' => false, 'message' => __( 'Template not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}
		return array(
			'success'  => true,
			'template' => Template_Query::to_summary( $post, $include_data ),
			/* translators: %d: template id */
			'message'  => sprintf( __( 'Returned template #%d.', 'acrossai-abilities-manager' ), $template_id ),
		);
	}
}
