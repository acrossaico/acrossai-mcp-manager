<?php
/**
 * Feature 111 — Remove Widget.
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
 * widgets/remove-widget — Remove Widget.
 */
class Remove_Widget extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/remove-widget',
			'args' => array(
				'label'               => __( 'Remove Widget', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a widget instance and its stored settings. There is no trash for widgets — this cannot be undone. If you might want the widget back, deactivate it instead, which keeps the configuration.', 'acrossai-abilities-manager' ),
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
						'confirm' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Must be true. Deleting a widget discards its settings permanently.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'widget_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'widget_id' => array( 'type' => 'string' ),
						'removed' => array( 'type' => 'boolean' ),
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
						'destructive' => true,
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
				'slug'   => 'widgets/deactivate-widget',
				'reason' => __( 'Keeps the widget and its settings, and simply stops it displaying.', 'acrossai-abilities-manager' ),
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

		if ( empty( $input['confirm'] ) ) {
			return array(
				'success'    => false,
				'error_code' => 'confirmation_required',
				'message'    => sprintf(
					/* translators: %s: widget id */
					__( 'Removing %s discards its settings permanently — widgets have no trash. Pass confirm: true to proceed, or use widgets/deactivate-widget to keep it.', 'acrossai-abilities-manager' ),
					$widget_id
				),
			);
		}

		$removed = Widget_Repository::delete( $widget_id );

		if ( is_wp_error( $removed ) ) {
			return array(
				'success'    => false,
				'error_code' => $removed->get_error_code(),
				'message'    => $removed->get_error_message(),
			);
		}

		return array(
			'success'   => true,
			'widget_id' => $widget_id,
			'removed'   => true,
			'message'   => sprintf(
				/* translators: 1: widget type name, 2: widget id */
				__( 'Removed the %1$s widget (%2$s) and its settings.', 'acrossai-abilities-manager' ),
				$existing['type_name'],
				$widget_id
			),
		);
	}
}
