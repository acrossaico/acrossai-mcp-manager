<?php
/**
 * Feature 067 — summarise the active theme + Elementor context.
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
 * Return active theme + Elementor version + active kit + viewport settings.
 */
class Get_Theme_Context extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/get-theme-context',
			'args' => array(
				'label'               => __( 'Get Elementor Theme Context', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the active theme, Elementor version, active kit, and viewport settings — foundation snapshot used by other design abilities.', 'acrossai-abilities-manager' ),
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
						'success'        => array( 'type' => 'boolean' ),
						'theme'          => array( 'type' => 'object' ),
						'elementor'      => array( 'type' => 'object' ),
						'active_kit'     => array( 'type' => 'object' ),
						'viewport'       => array( 'type' => 'object' ),
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

		$theme = wp_get_theme();
		$active_kit_id = (int) get_option( 'elementor_active_kit', 0 );
		$active_kit    = array( 'id' => $active_kit_id, 'title' => '' );
		if ( $active_kit_id > 0 ) {
			$kit_post = get_post( $active_kit_id );
			if ( $kit_post instanceof \WP_Post ) {
				$active_kit['title'] = (string) $kit_post->post_title;
			}
		}

		return array(
			'success' => true,
			'theme'   => array(
				'name'           => (string) $theme->get( 'Name' ),
				'version'        => (string) $theme->get( 'Version' ),
				'stylesheet'     => (string) $theme->get_stylesheet(),
				'template'       => (string) $theme->get_template(),
				'is_block_theme' => wp_is_block_theme(),
			),
			'elementor' => array(
				'version'                     => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
				'pro_version'                 => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '',
				'container_experiment_active' => class_exists( '\Elementor\Plugin' ),
			),
			'active_kit'     => $active_kit,
			'viewport'       => array(
				'tablet_breakpoint' => (int) get_option( 'elementor_viewport_lg', 1024 ),
				'mobile_breakpoint' => (int) get_option( 'elementor_viewport_md', 768 ),
			),
			'guidance_basis' => 'grounded in Elementor.com official documentation',
			'message'        => __( 'Returned Elementor theme context.', 'acrossai-abilities-manager' ),
		);
	}
}
