<?php
/**
 * Feature 067 — update Elementor maintenance mode settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Enable / disable Elementor maintenance mode with mode selection.
 */
class Update_Maintenance_Mode extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/update-maintenance-mode',
			'args' => array(
				'label'               => __( 'Update Elementor Maintenance Mode', 'acrossai-abilities-manager' ),
				'description'         => __( 'Enable or disable Elementor maintenance mode. Set mode (maintenance | coming_soon), template ID, and exclude rules.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'enabled'       => array( 'type' => 'boolean' ),
						'mode'          => array( 'type' => 'string', 'enum' => array( 'maintenance', 'coming_soon' ) ),
						'template_id'   => array( 'type' => 'integer', 'minimum' => 0 ),
						'exclude_mode'  => array( 'type' => 'string', 'enum' => array( 'logged_in', 'custom', '' ) ),
						'exclude_roles' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
					'required'             => array( 'enabled' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'     => array( 'type' => 'boolean' ),
						'enabled'     => array( 'type' => 'boolean' ),
						'mode'        => array( 'type' => 'string' ),
						'template_id' => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-system',
						'sub_group_label' => __( 'System & Maintenance', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$enabled       = (bool) ( $input['enabled'] ?? false );
		$mode          = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : 'maintenance';
		$template_id   = absint( $input['template_id'] ?? 0 );
		$exclude_mode  = isset( $input['exclude_mode'] ) ? sanitize_key( (string) $input['exclude_mode'] ) : '';
		$exclude_roles = isset( $input['exclude_roles'] ) && is_array( $input['exclude_roles'] )
			? array_map( 'sanitize_key', $input['exclude_roles'] )
			: array();

		if ( $enabled ) {
			update_option( 'elementor_maintenance_mode_mode', $mode );
			if ( $template_id > 0 ) {
				update_option( 'elementor_maintenance_mode_template_id', $template_id );
			}
		} else {
			// Disabled — clear the mode.
			update_option( 'elementor_maintenance_mode_mode', '' );
		}
		update_option( 'elementor_maintenance_mode_exclude_mode', $exclude_mode );
		update_option( 'elementor_maintenance_mode_exclude_roles', $exclude_roles );

		return array(
			'success'     => true,
			'enabled'     => $enabled,
			'mode'        => $enabled ? $mode : '',
			'template_id' => $template_id,
			'message'     => $enabled
				? sprintf( /* translators: %s: mode */ __( 'Enabled Elementor maintenance mode (%s).', 'acrossai-abilities-manager' ), $mode )
				: __( 'Disabled Elementor maintenance mode.', 'acrossai-abilities-manager' ),
		);
	}
}
