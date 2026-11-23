<?php
/**
 * Feature 111 — Move Widget.
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
 * widgets/move-widget — Move Widget.
 */
class Move_Widget extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/move-widget',
			'args' => array(
				'label'               => __( 'Move Widget', 'acrossai-abilities-manager' ),
				'description'         => __( 'Move a widget to another sidebar, or to another position within its current one. Its settings are untouched. Moving to "wp_inactive_widgets" keeps the widget and its configuration but stops it displaying, which is what the widgets screen calls making a widget inactive.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-widgets',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'widget_id' => array(
							'type'        => 'string',
							'description' => __( 'The widget instance id, e.g. "text-3" — a type followed by its instance number.', 'acrossai-abilities-manager' ),
						),
						'sidebar' => array(
							'type'        => 'string',
							'description' => __( 'Target sidebar id. Use "wp_inactive_widgets" to keep a widget configured but not displayed. widgets/get-widget-management-status lists what exists, including sidebars left behind by a previous theme.', 'acrossai-abilities-manager' ),
						),
						'position' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( '0-based position within the sidebar. Omit to append.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'widget_id', 'sidebar' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'widget' => array( 'type' => 'object' ),
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
	 * Where a caller usually goes next.
	 *
	 * @return array<int, array<string, string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'widgets/reorder-sidebar',
				'reason' => __( 'To set the order of a whole sidebar at once.', 'acrossai-abilities-manager' ),
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
		$widget_id = isset( $input['widget_id'] ) ? (string) $input['widget_id'] : '';
		$existing  = Widget_Repository::describe( $widget_id );

		if ( is_wp_error( $existing ) ) {
			return array(
				'success'    => false,
				'error_code' => $existing->get_error_code(),
				'message'    => $existing->get_error_message(),
			);
		}

		$placed = Widget_Repository::place(
			$widget_id,
			(string) $input['sidebar'],
			isset( $input['position'] ) ? (int) $input['position'] : null
		);

		if ( is_wp_error( $placed ) ) {
			return array(
				'success'    => false,
				'error_code' => $placed->get_error_code(),
				'message'    => $placed->get_error_message(),
			);
		}

		$row = Widget_Repository::describe( $widget_id );

		return array(
			'success' => true,
			'widget'  => is_wp_error( $row ) ? array() : $row,
			'message' => sprintf(
				/* translators: 1: widget id, 2: sidebar id, 3: position */
				__( 'Moved %1$s to %2$s at position %3$d.', 'acrossai-abilities-manager' ),
				$widget_id,
				(string) $input['sidebar'],
				is_wp_error( $row ) ? 0 : (int) $row['position']
			),
		);
	}
}
