<?php
/**
 * Feature 111 — Get Widget.
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
 * widgets/get-widget — Get Widget.
 */
class Get_Widget extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/get-widget',
			'args' => array(
				'label'               => __( 'Get Widget', 'acrossai-abilities-manager' ),
				'description'         => __( 'One widget instance: its type, its stored settings, which sidebar it sits in and at what position, and whether that placement means it actually renders.', 'acrossai-abilities-manager' ),
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
					),
					'required'             => array( 'widget_id' ),
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
						'readonly'    => true,
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
				'slug'   => 'widgets/update-widget',
				'reason' => __( 'Change its settings.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'widgets/move-widget',
				'reason' => __( 'Put it somewhere else.', 'acrossai-abilities-manager' ),
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
		$row       = Widget_Repository::describe( $widget_id );

		if ( is_wp_error( $row ) ) {
			return array(
				'success'    => false,
				'error_code' => $row->get_error_code(),
				'message'    => $row->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'widget'  => $row,
			'message' => sprintf(
				/* translators: 1: widget type name, 2: sidebar id */
				__( '%1$s widget in %2$s.', 'acrossai-abilities-manager' ),
				$row['type_name'],
				'' !== $row['sidebar'] ? $row['sidebar'] : __( 'no sidebar', 'acrossai-abilities-manager' )
			),
		);
	}
}
