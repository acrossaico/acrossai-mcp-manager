<?php
/**
 * Feature 067 — export an Elementor template as JSON.
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

class Export_Template extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/export-template',
			'args' => array(
				'label'               => __( 'Export Elementor Template', 'acrossai-abilities-manager' ),
				'description'         => __( 'Export an Elementor template as a JSON-encodable object for portability across sites.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'   => array( 'template_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'template_id' => array( 'type' => 'integer' ),
						'data'        => array( 'type' => 'object' ),
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
		$template_id = absint( $input['template_id'] ?? 0 );
		$post = get_post( $template_id );
		if ( ! $post instanceof \WP_Post || Template_Query::CPT !== $post->post_type ) {
			return array( 'success' => false, 'message' => __( 'Template not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}
		$summary = Template_Query::to_summary( $post, true );
		$export  = array(
			'version'       => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			'title'         => $summary['title'],
			'template_type' => $summary['template_type'],
			'sub_type'      => $summary['sub_type'],
			'page_settings' => get_post_meta( $template_id, '_elementor_page_settings', true ) ?: array(),
			'content'       => $summary['data'],
			'conditions'    => $summary['conditions'],
		);
		return array(
			'success'     => true,
			'template_id' => $template_id,
			'data'        => $export,
			/* translators: %d: template id */
			'message'     => sprintf( __( 'Exported template #%d.', 'acrossai-abilities-manager' ), $template_id ),
		);
	}
}
