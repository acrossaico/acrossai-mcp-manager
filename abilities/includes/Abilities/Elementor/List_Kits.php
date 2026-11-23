<?php
/**
 * Feature 067 — list Elementor kits.
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

class List_Kits extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/list-kits',
			'args' => array(
				'label'               => __( 'List Elementor Kits', 'acrossai-abilities-manager' ),
				'description'         => __( 'List all Elementor Kits (elementor_library posts with template_type=kit). Marks the active kit.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'required' => array(), 'additionalProperties' => false ),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'kits'          => array( 'type' => 'array' ),
						'active_kit_id' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
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
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$active_id = (int) get_option( 'elementor_active_kit', 0 );
		$posts     = Template_Query::query( array( 'template_type' => 'kit', 'status' => 'any', 'limit' => -1 ) );
		$kits      = array();
		foreach ( $posts as $post ) {
			$kits[] = array(
				'id'        => (int) $post->ID,
				'title'     => (string) $post->post_title,
				'status'    => (string) $post->post_status,
				'is_active' => $active_id === (int) $post->ID,
			);
		}
		return array(
			'success'       => true,
			'kits'          => $kits,
			'active_kit_id' => $active_id,
			/* translators: %d: count */
			'message'       => sprintf( __( 'Returned %d kits.', 'acrossai-abilities-manager' ), count( $kits ) ),
		);
	}
}
