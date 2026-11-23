<?php
/**
 * Feature 067 — read Elementor kit settings.
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

class Get_Kit_Settings extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/get-kit-settings',
			'args' => array(
				'label'               => __( 'Get Elementor Kit Settings', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the settings for an Elementor kit (defaults to active kit) — colors, typography, buttons, forms, layout, custom CSS.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array( 'kit_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'required' => array(),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'kit_id'     => array( 'type' => 'integer' ),
						'settings'   => array( 'type' => 'object' ),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-kits', 'sub_group_label' => __( 'Kits & Global Styles', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'kit_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$kit_id = absint( $input['kit_id'] ?? 0 );
		if ( 0 === $kit_id ) {
			$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		}
		if ( $kit_id <= 0 ) {
			return array( 'success' => false, 'kit_id' => 0, 'message' => __( 'No kit found.', 'acrossai-abilities-manager' ), 'error_code' => 'kit_not_found' );
		}
		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$settings = is_array( $settings ) ? $settings : array();
		return array(
			'success'  => true,
			'kit_id'   => $kit_id,
			'settings' => $settings,
			/* translators: %d: kit id */
			'message'  => sprintf( __( 'Returned settings for kit #%d.', 'acrossai-abilities-manager' ), $kit_id ),
		);
	}
}
