<?php
/**
 * Feature 111 — Add Widget.
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
 * widgets/add-widget — Add Widget.
 */
class Add_Widget extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/add-widget',
			'args' => array(
				'label'               => __( 'Add Widget', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new instance of a widget type and place it in a sidebar. Settings go through the widget type\'s own update handler, so they are validated the same way the widgets screen validates them. Omit the sidebar to create the instance without displaying it.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-widgets',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id_base' => array(
							'type'        => 'string',
							'description' => __( 'The widget type, e.g. "text" or "categories". widgets/list-widget-types has the full list.', 'acrossai-abilities-manager' ),
						),
						'settings' => array(
							'type'        => 'object',
							'description' => __( 'The widget\'s settings. Which keys are accepted depends on the type.', 'acrossai-abilities-manager' ),
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
					'required'             => array( 'id_base' ),
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
						'idempotent'  => false,
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
				'slug'   => 'widgets/list-widget-types',
				'reason' => __( 'Find the id_base and see how many instances already exist.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'widgets/get-widget-management-status',
				'reason' => __( 'If the widget does not appear, check whether the theme registers the sidebar at all.', 'acrossai-abilities-manager' ),
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
		$id_base = isset( $input['id_base'] ) ? (string) $input['id_base'] : '';

		if ( null === Widget_Repository::widget_object( $id_base ) ) {
			return array(
				'success'    => false,
				'error_code' => 'unknown_widget_type',
				'message'    => sprintf(
					/* translators: %s: widget type */
					__( 'No registered widget type "%s". Use widgets/list-widget-types to see what is available.', 'acrossai-abilities-manager' ),
					$id_base
				),
			);
		}

		$number   = Widget_Repository::next_number( $id_base );
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$saved    = Widget_Repository::save_instance( $id_base, $number, $settings );

		if ( is_wp_error( $saved ) ) {
			return array(
				'success'    => false,
				'error_code' => $saved->get_error_code(),
				'message'    => $saved->get_error_message(),
			);
		}

		$widget_id = $id_base . '-' . $number;

		if ( ! empty( $input['sidebar'] ) ) {
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

		}

		$row = Widget_Repository::describe( $widget_id );

		return array(
			'success' => true,
			'widget'  => is_wp_error( $row ) ? array() : $row,
			'message' => sprintf(
				/* translators: 1: widget id, 2: sidebar id */
				__( 'Created %1$s in %2$s.', 'acrossai-abilities-manager' ),
				$widget_id,
				! empty( $input['sidebar'] ) ? (string) $input['sidebar'] : __( 'no sidebar', 'acrossai-abilities-manager' )
			),
		);
	}
}
