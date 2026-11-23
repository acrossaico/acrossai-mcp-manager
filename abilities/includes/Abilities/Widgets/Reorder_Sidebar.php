<?php
/**
 * Feature 111 — Reorder Sidebar.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Widgets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Widgets;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Widget_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * widgets/reorder-sidebar — Reorder Sidebar.
 */
class Reorder_Sidebar extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/reorder-sidebar',
			'args' => array(
				'label'               => __( 'Reorder Sidebar', 'acrossai-abilities-manager' ),
				'description'         => __( 'Set the exact order of the widgets in a sidebar. The list must name every widget currently in that sidebar and nothing else — a partial list is refused rather than applied, because accepting one would silently drop every widget left out, which looks like a reorder and is a deletion.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-widgets',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'sidebar' => array(
							'type'        => 'string',
							'description' => __( 'The sidebar to reorder.', 'acrossai-abilities-manager' ),
						),
						'widget_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Every widget currently in that sidebar, in the order wanted.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'sidebar', 'widget_ids' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'sidebar' => array( 'type' => 'object' ),
						'error_code' => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'widgets',
						'sub_group_label' => __( 'Widgets', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Run.
	 *
	 * @param  array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$sidebar = isset( $input['sidebar'] ) ? (string) $input['sidebar'] : '';
		$ids     = isset( $input['widget_ids'] ) && is_array( $input['widget_ids'] ) ? array_map( 'strval', $input['widget_ids'] ) : array();
		$done    = Widget_Repository::reorder( $sidebar, $ids );

		if ( is_wp_error( $done ) ) {
			return array(
				'success'    => false,
				'error_code' => $done->get_error_code(),
				'message'    => $done->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'sidebar' => array(
				'id'      => $sidebar,
				'widgets' => Widget_Repository::placements()[ $sidebar ] ?? array(),
			),
			'message' => sprintf(
				/* translators: 1: number of widgets, 2: sidebar id */
				__( 'Reordered %1$d widget(s) in %2$s.', 'acrossai-abilities-manager' ),
				count( $ids ),
				$sidebar
			),
		);
	}
}
