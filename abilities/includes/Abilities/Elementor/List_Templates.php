<?php
/**
 * Feature 067 — list saved Elementor templates.
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

class List_Templates extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/list-templates',
			'args' => array(
				'label'               => __( 'List Elementor Templates', 'acrossai-abilities-manager' ),
				'description'         => __( 'List saved Elementor templates (elementor_library CPT). Filter by template_type and status.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_type' => array( 'type' => 'string' ),
						'status'        => array( 'type' => 'string', 'default' => 'publish' ),
						'limit'         => array( 'type' => 'integer', 'default' => 50 ),
						'offset'        => array( 'type' => 'integer', 'default' => 0 ),
					),
					'required'   => array(),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'templates' => array( 'type' => 'array' ),
						'count'     => array( 'type' => 'integer' ),
						'message'   => array( 'type' => 'string' ),
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
		$posts = Template_Query::query( array(
			'template_type' => (string) ( $input['template_type'] ?? '' ),
			'status'        => (string) ( $input['status'] ?? 'publish' ),
			'limit'         => (int) ( $input['limit'] ?? 50 ),
			'offset'        => (int) ( $input['offset'] ?? 0 ),
		) );
		$templates = array_map( static fn( $p ) => Template_Query::to_summary( $p, false ), $posts );

		return array(
			'success'   => true,
			'templates' => array_values( $templates ),
			'count'     => count( $templates ),
			/* translators: %d: count */
			'message'   => sprintf( __( 'Returned %d templates.', 'acrossai-abilities-manager' ), count( $templates ) ),
		);
	}
}
