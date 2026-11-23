<?php
/**
 * Feature 067 — set the active Elementor kit.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Template_Query;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

class Set_Active_Kit extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/set-active-kit',
			'args' => array(
				'label'               => __( 'Set Active Elementor Kit', 'acrossai-abilities-manager' ),
				'description'         => __( 'Switch the site-wide active Elementor kit. Invalidates Elementor CSS cache site-wide.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array( 'kit_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'required' => array( 'kit_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'previous_kit_id'  => array( 'type' => 'integer' ),
						'active_kit_id'    => array( 'type' => 'integer' ),
						'message'          => array( 'type' => 'string' ),
						'error_code'       => array( 'type' => 'string' ),
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
			return array( 'success' => false, 'previous_kit_id' => 0, 'active_kit_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$kit_id = absint( $input['kit_id'] ?? 0 );
		$post   = get_post( $kit_id );
		if ( ! $post instanceof \WP_Post || Template_Query::CPT !== $post->post_type ) {
			return array( 'success' => false, 'previous_kit_id' => 0, 'active_kit_id' => 0, 'message' => __( 'Kit not found.', 'acrossai-abilities-manager' ), 'error_code' => 'kit_not_found' );
		}
		$previous = (int) get_option( 'elementor_active_kit', 0 );
		update_option( 'elementor_active_kit', $kit_id );
		Document_Repository::invalidate_cache( $kit_id, 'site' );
		return array(
			'success'          => true,
			'previous_kit_id'  => $previous,
			'active_kit_id'    => $kit_id,
			/* translators: %d: kit id */
			'message'          => sprintf( __( 'Active Elementor kit set to #%d.', 'acrossai-abilities-manager' ), $kit_id ),
		);
	}
}
