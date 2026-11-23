<?php
/**
 * Site introspection read — registered sidebar registry (Feature 063).
 *
 * Iterates $GLOBALS['wp_registered_sidebars'] and projects each entry
 * into the id/name/description + widget-wrapper HTML fragments schema
 * shape.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Widgets
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Widgets;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List_Sidebars ability class.
 */
class List_Sidebars extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/list-sidebars',
			'args' => array(
				'label'               => __( 'List Sidebars', 'acrossai-abilities-manager' ),
				'description'         => __( 'Enumerate every registered sidebar with its identifier, display name, description, and widget-wrapper HTML fragments (before_widget, after_widget, before_title, after_title).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-widgets',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'sidebars' => array( 'type' => 'array' ),
						'message'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'introspection',
						'sub_group_label' => __( 'Introspection', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$registered = isset( $GLOBALS['wp_registered_sidebars'] ) && is_array( $GLOBALS['wp_registered_sidebars'] )
			? $GLOBALS['wp_registered_sidebars']
			: array();

		$sidebars = array();
		foreach ( $registered as $id => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$sidebars[] = array(
				'id'            => (string) ( $meta['id'] ?? $id ),
				'name'          => (string) ( $meta['name'] ?? '' ),
				'description'   => (string) ( $meta['description'] ?? '' ),
				'before_widget' => (string) ( $meta['before_widget'] ?? '' ),
				'after_widget'  => (string) ( $meta['after_widget'] ?? '' ),
				'before_title'  => (string) ( $meta['before_title'] ?? '' ),
				'after_title'   => (string) ( $meta['after_title'] ?? '' ),
			);
		}

		return array(
			'success'  => true,
			'sidebars' => $sidebars,
			'message'  => __( 'Sidebars fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
