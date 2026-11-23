<?php
/**
 * Feature 067 — convenience wrapper: add an Elementor heading widget.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over Add_Widget with a type-specific input schema
 * covering the heading widget's most-common settings.
 */
class Add_Heading extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/add-heading',
			'args' => array(
				'label'               => __( 'Add Elementor Heading', 'acrossai-abilities-manager' ),
				'description'         => __( 'Insert an Elementor heading widget with title, header size (h1-h6), alignment, and colour.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'parent_id'   => array( 'type' => array( 'string', 'null' ) ),
						'position'    => array( 'type' => 'integer', 'minimum' => 0 ),
						'title'       => array( 'type' => 'string' ),
						'header_size' => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) ),
						'align'       => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
						'title_color' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'element'    => array( 'type' => 'object' ),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-elements',
						'sub_group_label' => __( 'Elements & Widgets', 'acrossai-abilities-manager' ),
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
		$settings = array();
		foreach ( array( 'title', 'header_size', 'align', 'title_color' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = $input[ $key ];
			}
		}
		$delegate = new Add_Widget();
		return $delegate->execute( array(
			'post_id'     => $input['post_id'] ?? 0,
			'widget_type' => 'heading',
			'parent_id'   => $input['parent_id'] ?? null,
			'position'    => $input['position'] ?? 0,
			'settings'    => $settings,
		) );
	}
}
