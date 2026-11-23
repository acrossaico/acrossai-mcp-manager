<?php
/**
 * Feature 067 — create an Elementor Pro custom code snippet.
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

class Create_Custom_Code extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/create-custom-code',
			'args' => array(
				'label'               => __( 'Create Elementor Pro Custom Code', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new Elementor Pro Custom Code snippet with title, code, location, priority, and status. Requires Elementor Pro.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'title'    => array( 'type' => 'string' ),
						'code'     => array( 'type' => 'string' ),
						'location' => array( 'type' => 'string', 'enum' => array( 'head', 'body_start', 'body_end', 'footer' ) ),
						'priority' => array( 'type' => 'integer', 'default' => 10 ),
						'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ), 'default' => 'publish' ),
					),
					'required' => array( 'title', 'code', 'location' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'snippet_id' => array( 'type' => 'integer' ),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-custom-code', 'sub_group_label' => __( 'Custom Code', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$pro = Document_Repository::assert_elementor_pro_available();
		if ( is_wp_error( $pro ) ) {
			return array( 'success' => false, 'message' => (string) $pro->get_error_message(), 'error_code' => (string) $pro->get_error_code() );
		}
		$title    = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$code     = isset( $input['code'] ) ? (string) $input['code'] : '';
		$location = isset( $input['location'] ) ? sanitize_key( (string) $input['location'] ) : '';
		$priority = (int) ( $input['priority'] ?? 10 );
		$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'publish';

		if ( '' === $title || '' === $code || '' === $location ) {
			return array( 'success' => false, 'message' => __( 'title, code, and location are required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}

		$snippet_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_content' => $code,
			'post_type'    => List_Custom_Code::CPT,
			'post_status'  => $status,
		), true );
		if ( is_wp_error( $snippet_id ) ) {
			return array( 'success' => false, 'message' => (string) $snippet_id->get_error_message(), 'error_code' => (string) $snippet_id->get_error_code() );
		}
		$snippet_id = (int) $snippet_id;
		update_post_meta( $snippet_id, '_elementor_snippet_location', $location );
		update_post_meta( $snippet_id, '_elementor_snippet_priority', $priority );

		return array(
			'success'    => true,
			'snippet_id' => $snippet_id,
			/* translators: %d: id */
			'message'    => sprintf( __( 'Created Pro custom code snippet #%d.', 'acrossai-abilities-manager' ), $snippet_id ),
		);
	}
}
