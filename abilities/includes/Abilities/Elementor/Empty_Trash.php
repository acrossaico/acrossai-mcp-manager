<?php
/**
 * Feature 067 — permanently delete all trashed Elementor templates.
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

class Empty_Trash extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/empty-trash',
			'args' => array(
				'label'               => __( 'Empty Elementor Template Trash', 'acrossai-abilities-manager' ),
				'description'         => __( 'Permanently delete every trashed Elementor template. Requires confirm=true.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'confirm' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'deleted_count' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-templates', 'sub_group_label' => __( 'Templates & Theme Builder', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		if ( empty( $input['confirm'] ) ) {
			return array( 'success' => false, 'message' => __( 'confirm=true is required.', 'acrossai-abilities-manager' ), 'error_code' => 'force_delete_required' );
		}
		$trashed = Template_Query::query( array( 'status' => 'trash', 'limit' => -1 ) );
		$deleted = 0;
		foreach ( $trashed as $post ) {
			if ( wp_delete_post( (int) $post->ID, true ) ) {
				++$deleted;
			}
		}
		return array(
			'success'       => true,
			'deleted_count' => $deleted,
			/* translators: %d: deleted count */
			'message'       => sprintf( __( 'Permanently deleted %d trashed templates.', 'acrossai-abilities-manager' ), $deleted ),
		);
	}
}
