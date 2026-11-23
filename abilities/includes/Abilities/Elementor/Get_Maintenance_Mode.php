<?php
/**
 * Feature 067 — read Elementor maintenance mode settings.
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
 * Return Elementor maintenance mode settings — mode, template_id,
 * exclude mode, exclude roles.
 */
class Get_Maintenance_Mode extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/get-maintenance-mode',
			'args' => array(
				'label'               => __( 'Get Elementor Maintenance Mode', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the current Elementor maintenance mode settings: mode, active template, exclude rules.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'enabled'      => array( 'type' => 'boolean' ),
						'mode'         => array( 'type' => 'string' ),
						'template_id'  => array( 'type' => 'integer' ),
						'exclude_mode' => array( 'type' => 'string' ),
						'exclude_roles' => array( 'type' => 'array' ),
						'message'      => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
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
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
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
		$mode         = (string) get_option( 'elementor_maintenance_mode_mode', '' );
		$template_id  = (int) get_option( 'elementor_maintenance_mode_template_id', 0 );
		$exclude_mode = (string) get_option( 'elementor_maintenance_mode_exclude_mode', '' );
		$roles        = get_option( 'elementor_maintenance_mode_exclude_roles', array() );

		return array(
			'success'       => true,
			'enabled'       => '' !== $mode,
			'mode'          => $mode,
			'template_id'   => $template_id,
			'exclude_mode'  => $exclude_mode,
			'exclude_roles' => is_array( $roles ) ? $roles : array(),
			'message'       => __( 'Read Elementor maintenance mode settings.', 'acrossai-abilities-manager' ),
		);
	}
}
