<?php
/**
 * Feature 067 — convenience wrapper: add an Elementor text-editor widget.
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
 * Insert an Elementor text-editor widget.
 */
class Add_Text_Editor extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/add-text-editor',
			'args' => array(
				'label'               => __( 'Add Elementor Text Editor', 'acrossai-abilities-manager' ),
				'description'         => __( 'Insert an Elementor text-editor widget with HTML content and alignment.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'parent_id' => array( 'type' => array( 'string', 'null' ) ),
						'position'  => array( 'type' => 'integer', 'minimum' => 0 ),
						'editor'    => array( 'type' => 'string' ),
						'align'     => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
					),
					'required'             => array( 'post_id', 'editor' ),
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
		foreach ( array( 'editor', 'align' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = $input[ $key ];
			}
		}
		$delegate = new Add_Widget();
		return $delegate->execute( array(
			'post_id'     => $input['post_id'] ?? 0,
			'widget_type' => 'text-editor',
			'parent_id'   => $input['parent_id'] ?? null,
			'position'    => $input['position'] ?? 0,
			'settings'    => $settings,
		) );
	}
}
