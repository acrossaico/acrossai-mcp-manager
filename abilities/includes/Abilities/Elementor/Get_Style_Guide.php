<?php
/**
 * Feature 067 — return active Elementor kit's style-guide summary.
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
 * Build a style-guide summary from the active kit's page settings:
 * global colors, typography, buttons, form defaults, layout, custom CSS.
 */
class Get_Style_Guide extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/get-style-guide',
			'args' => array(
				'label'               => __( 'Get Elementor Style Guide', 'acrossai-abilities-manager' ),
				'description'         => __( 'Build a style-guide summary from the active Elementor kit: global colors, typography, buttons, form defaults, layout, custom CSS.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'kit_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'kit_id'         => array( 'type' => 'integer' ),
						'colors'         => array( 'type' => 'array' ),
						'typography'     => array( 'type' => 'array' ),
						'buttons'        => array( 'type' => 'object' ),
						'forms'          => array( 'type' => 'object' ),
						'layout'         => array( 'type' => 'object' ),
						'custom_css'     => array( 'type' => 'string' ),
						'guidance_basis' => array( 'type' => 'string' ),
						'message'        => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-guidance',
						'sub_group_label' => __( 'Discovery & Guidance', 'acrossai-abilities-manager' ),
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
		$kit_id = absint( $input['kit_id'] ?? 0 );
		if ( 0 === $kit_id ) {
			$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		}
		if ( $kit_id <= 0 ) {
			return array( 'success' => false, 'kit_id' => 0, 'message' => __( 'No active Elementor kit found.', 'acrossai-abilities-manager' ), 'error_code' => 'kit_not_found' );
		}

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$settings = is_array( $settings ) ? $settings : array();

		$colors     = array_merge(
			isset( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ? $settings['system_colors'] : array(),
			isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] ) ? $settings['custom_colors'] : array()
		);
		$typography = array_merge(
			isset( $settings['system_typography'] ) && is_array( $settings['system_typography'] ) ? $settings['system_typography'] : array(),
			isset( $settings['custom_typography'] ) && is_array( $settings['custom_typography'] ) ? $settings['custom_typography'] : array()
		);

		return array(
			'success'        => true,
			'kit_id'         => $kit_id,
			'colors'         => $colors,
			'typography'     => $typography,
			'buttons'        => isset( $settings['button_'] ) && is_array( $settings['button_'] ) ? $settings['button_'] : array(),
			'forms'          => isset( $settings['form_'] ) && is_array( $settings['form_'] ) ? $settings['form_'] : array(),
			'layout'         => isset( $settings['container_'] ) && is_array( $settings['container_'] ) ? $settings['container_'] : array(),
			'custom_css'     => isset( $settings['custom_css'] ) ? (string) $settings['custom_css'] : '',
			'guidance_basis' => 'grounded in Elementor.com official documentation',
			/* translators: %d: kit id */
			'message'        => sprintf( __( 'Returned style guide for kit #%d.', 'acrossai-abilities-manager' ), $kit_id ),
		);
	}
}
