<?php
/**
 * Feature 067 — update Elementor document-level page settings.
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
 * Merge new page_settings into _elementor_page_settings post meta.
 * Guarded by force_replace when the new payload is materially smaller.
 */
class Update_Page_Settings extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/update-page-settings',
			'args' => array(
				'label'               => __( 'Update Elementor Page Settings', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update the Elementor document-level page settings (layout, title, background, custom CSS). Merges new keys into existing settings; use force_replace=true to overwrite the full settings object.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'page_settings' => array( 'type' => 'object' ),
						'force_replace' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'             => array( 'post_id', 'page_settings' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'page_settings' => array( 'type' => 'object' ),
						'message'       => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-documents',
						'sub_group_label' => __( 'Documents', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
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
			return array( 'success' => false, 'post_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$post_id       = absint( $input['post_id'] ?? 0 );
		$new_settings  = isset( $input['page_settings'] ) && is_array( $input['page_settings'] ) ? $input['page_settings'] : array();
		$force_replace = ! empty( $input['force_replace'] );

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => (string) $doc->get_error_message(), 'error_code' => (string) $doc->get_error_code() );
		}

		$existing = is_array( $doc['page_settings'] ) ? $doc['page_settings'] : array();
		if ( ! $force_replace && count( $existing ) > 0 && count( $new_settings ) < ( count( $existing ) / 2 ) ) {
			return array(
				'success'    => false,
				'post_id'    => $post_id,
				'message'    => __( 'New page_settings is materially smaller than existing. Pass force_replace=true to overwrite.', 'acrossai-abilities-manager' ),
				'error_code' => 'force_replace_required',
			);
		}

		$merged = $force_replace ? $new_settings : array_merge( $existing, $new_settings );
		update_post_meta( $post_id, '_elementor_page_settings', $merged );
		Document_Repository::invalidate_cache( $post_id, 'post' );

		return array(
			'success'       => true,
			'post_id'       => $post_id,
			'page_settings' => $merged,
			/* translators: %d: post id */
			'message'       => sprintf( __( 'Updated page settings on post #%d.', 'acrossai-abilities-manager' ), $post_id ),
		);
	}
}
