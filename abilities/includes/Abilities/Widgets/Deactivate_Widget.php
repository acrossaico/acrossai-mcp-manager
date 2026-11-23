<?php
/**
 * Feature 111 — Deactivate Widget.
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
 * widgets/deactivate-widget — Deactivate Widget.
 */
class Deactivate_Widget extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/deactivate-widget',
			'args' => array(
				'label'               => __( 'Deactivate Widget', 'acrossai-abilities-manager' ),
				'description'         => __( 'Stop a widget displaying without losing it. It moves to the inactive store, keeping its settings, and can be moved back to a sidebar later. Prefer this to removing a widget you might want again.', 'acrossai-abilities-manager' ),
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
				'slug'   => 'widgets/move-widget',
				'reason' => __( 'To put it back into a sidebar later.', 'acrossai-abilities-manager' ),
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

		$moved = Widget_Repository::place( $widget_id, Widget_Repository::INACTIVE );

		if ( is_wp_error( $moved ) ) {
			return array(
				'success'    => false,
				'error_code' => $moved->get_error_code(),
				'message'    => $moved->get_error_message(),
			);
		}

		$row = Widget_Repository::describe( $widget_id );

		return array(
			'success' => true,
			'widget'  => is_wp_error( $row ) ? array() : $row,
			'message' => sprintf(
				/* translators: %s: widget id */
				__( '%s is now inactive. Its settings are kept and it can be moved back to a sidebar.', 'acrossai-abilities-manager' ),
				$widget_id
			),
		);
	}
}
