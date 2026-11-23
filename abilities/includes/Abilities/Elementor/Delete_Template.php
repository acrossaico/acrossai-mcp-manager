<?php
/**
 * Feature 067 — trash or permanently delete an Elementor template.
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

class Delete_Template extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/delete-template',
			'args' => array(
				'label'               => __( 'Delete Elementor Template', 'acrossai-abilities-manager' ),
				'description'         => __( 'Move an Elementor template to trash (default) or permanently delete when force=true.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'force'       => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'template_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'template_id' => array( 'type' => 'integer' ),
						'action'      => array( 'type' => 'string' ),
						'message'     => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
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
		$template_id = absint( $input['template_id'] ?? 0 );
		$force       = ! empty( $input['force'] );
		$post        = get_post( $template_id );
		if ( ! $post instanceof \WP_Post || Template_Query::CPT !== $post->post_type ) {
			return array( 'success' => false, 'message' => __( 'Template not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}
		$result = $force ? wp_delete_post( $template_id, true ) : wp_trash_post( $template_id );
		$action = $force ? 'deleted' : 'trashed';
		if ( ! $result ) {
			return array( 'success' => false, 'template_id' => $template_id, 'message' => __( 'Failed to delete template.', 'acrossai-abilities-manager' ), 'error_code' => 'delete_failed' );
		}
		return array(
			'success'     => true,
			'template_id' => $template_id,
			'action'      => $action,
			/* translators: 1: action, 2: template id */
			'message'     => sprintf( __( '%1$s template #%2$d.', 'acrossai-abilities-manager' ), ucfirst( $action ), $template_id ),
		);
	}
}
