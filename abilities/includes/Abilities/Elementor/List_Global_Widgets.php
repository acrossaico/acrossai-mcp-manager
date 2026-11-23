<?php
/**
 * Feature 067 — list Elementor global widgets.
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

/**
 * Return the list of global (reusable) widgets stored as
 * elementor_library posts with template_type=widget.
 */
class List_Global_Widgets extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/list-global-widgets',
			'args' => array(
				'label'               => __( 'List Elementor Global Widgets', 'acrossai-abilities-manager' ),
				'description'         => __( 'List all Elementor global (reusable) widgets — elementor_library posts with template_type=widget.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'required' => array(), 'additionalProperties' => false ),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'global_widgets'  => array( 'type' => 'array' ),
						'count'           => array( 'type' => 'integer' ),
						'message'         => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-kits', 'sub_group_label' => __( 'Kits & Global Styles', 'acrossai-abilities-manager' ) ),
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
		$posts   = Template_Query::query( array( 'template_type' => 'widget', 'status' => 'any', 'limit' => -1 ) );
		$widgets = array_map( static fn( $p ) => Template_Query::to_summary( $p, false ), $posts );
		return array(
			'success'        => true,
			'global_widgets' => array_values( $widgets ),
			'count'          => count( $widgets ),
			/* translators: %d: count */
			'message'        => sprintf( __( 'Returned %d global widgets.', 'acrossai-abilities-manager' ), count( $widgets ) ),
		);
	}
}
