<?php
/**
 * Site introspection read — per-sidebar widget assignments (Feature 063).
 *
 * Returns the per-sidebar widget-instance-identifier map plus the
 * registered-widgets metadata registry so callers can resolve instance
 * ids back to widget classes.
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
 * List_Widgets ability class.
 */
class List_Widgets extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/list-widgets',
			'args' => array(
				'label'               => __( 'List Widgets', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the per-sidebar widget-instance-id map and the registered-widgets metadata registry, sufficient for callers to resolve identifiers to widget classes.', 'acrossai-abilities-manager' ),
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
						'sidebars' => array( 'type' => 'object' ),
						'widgets'  => array( 'type' => 'object' ),
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
		$sidebars = wp_get_sidebars_widgets();
		if ( ! is_array( $sidebars ) ) {
			$sidebars = array();
		}

		$registered = isset( $GLOBALS['wp_registered_widgets'] ) && is_array( $GLOBALS['wp_registered_widgets'] )
			? $GLOBALS['wp_registered_widgets']
			: array();

		$widgets = array();
		foreach ( $registered as $instance_id => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$widgets[ (string) $instance_id ] = array(
				'name'      => (string) ( $meta['name'] ?? '' ),
				'classname' => (string) ( $meta['classname'] ?? '' ),
			);
		}

		return array(
			'success'  => true,
			'sidebars' => (object) $sidebars,
			'widgets'  => (object) $widgets,
			'message'  => __( 'Widgets fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
