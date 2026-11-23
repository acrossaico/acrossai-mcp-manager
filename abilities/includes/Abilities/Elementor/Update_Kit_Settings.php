<?php
/**
 * Feature 067 — update Elementor kit settings.
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

class Update_Kit_Settings extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/update-kit-settings',
			'args' => array(
				'label'               => __( 'Update Elementor Kit Settings', 'acrossai-abilities-manager' ),
				'description'         => __( 'Merge new kit settings into the active kit (or specified kit_id). force_replace=true replaces the full settings object.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'kit_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
						'settings'      => array( 'type' => 'object' ),
						'force_replace' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required' => array( 'settings' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'kit_id'       => array( 'type' => 'integer' ),
						'changed_keys' => array( 'type' => 'array' ),
						'message'      => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-kits', 'sub_group_label' => __( 'Kits & Global Styles', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'kit_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$kit_id        = absint( $input['kit_id'] ?? 0 );
		$new_settings  = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$force_replace = ! empty( $input['force_replace'] );
		if ( 0 === $kit_id ) {
			$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		}
		if ( $kit_id <= 0 || ! get_post( $kit_id ) ) {
			return array( 'success' => false, 'kit_id' => $kit_id, 'message' => __( 'Kit not found.', 'acrossai-abilities-manager' ), 'error_code' => 'kit_not_found' );
		}
		$existing = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$existing = is_array( $existing ) ? $existing : array();
		$merged   = $force_replace ? $new_settings : array_merge( $existing, $new_settings );
		update_post_meta( $kit_id, '_elementor_page_settings', $merged );
		Document_Repository::invalidate_cache( $kit_id, 'site' );
		return array(
			'success'      => true,
			'kit_id'       => $kit_id,
			'changed_keys' => array_keys( $new_settings ),
			/* translators: 1: count, 2: kit id */
			'message'      => sprintf( __( 'Updated %1$d settings on kit #%2$d.', 'acrossai-abilities-manager' ), count( $new_settings ), $kit_id ),
		);
	}
}
