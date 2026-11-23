<?php
/**
 * Feature 111 — Update Widget.
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
 * widgets/update-widget — Update Widget.
 */
class Update_Widget extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/update-widget',
			'args' => array(
				'label'               => __( 'Update Widget', 'acrossai-abilities-manager' ),
				'description'         => __( 'Change a widget instance\'s settings. Only the keys supplied are changed; the rest are kept. Settings go through the widget type\'s own update handler, which is what the widgets screen uses — so a type that validates or rejects input does so here too, and a rejection is reported rather than written.', 'acrossai-abilities-manager' ),
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
						'settings' => array(
							'type'        => 'object',
							'description' => __( 'Settings to change, merged over the current ones.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'widget_id', 'settings' ),
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
				'slug'   => 'widgets/get-widget',
				'reason' => __( 'Read the current settings first — the accepted keys depend on the widget type.', 'acrossai-abilities-manager' ),
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

		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( array() === $settings ) {
			return array(
				'success'    => false,
				'error_code' => 'invalid_input',
				'message'    => __( 'Supply at least one setting to change.', 'acrossai-abilities-manager' ),
			);
		}

		$saved = Widget_Repository::save_instance( $existing['id_base'], $existing['number'], $settings );

		if ( is_wp_error( $saved ) ) {
			return array(
				'success'    => false,
				'error_code' => $saved->get_error_code(),
				'message'    => $saved->get_error_message(),
			);
		}

		$row = Widget_Repository::describe( $widget_id );

		return array(
			'success' => true,
			'widget'  => is_wp_error( $row ) ? array() : $row,
			'message' => sprintf(
				/* translators: 1: comma-separated setting names, 2: widget id */
				__( 'Updated %1$s on %2$s.', 'acrossai-abilities-manager' ),
				implode( ', ', array_keys( $settings ) ),
				$widget_id
			),
		);
	}
}
