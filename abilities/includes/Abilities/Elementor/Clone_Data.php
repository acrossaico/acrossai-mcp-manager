<?php
/**
 * Feature 067 — clone Elementor document data from one post to another.
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
 * Copy the full Elementor tree (and optionally page settings) from
 * source_post_id to target_post_id. Regenerates every element ID in
 * the cloned tree. Guarded by force_replace when target has content.
 */
class Clone_Data extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/clone-data',
			'args' => array(
				'label'               => __( 'Clone Elementor Document Data', 'acrossai-abilities-manager' ),
				'description'         => __( 'Copy the full Elementor tree from one post to another with fresh element IDs throughout. Optionally include page settings. Guarded by force_replace when the target already has content.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'source_post_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
						'target_post_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
						'include_page_settings' => array( 'type' => 'boolean', 'default' => false ),
						'force_replace'         => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'             => array( 'source_post_id', 'target_post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'source_post_id' => array( 'type' => 'integer' ),
						'target_post_id' => array( 'type' => 'integer' ),
						'element_count'  => array( 'type' => 'integer' ),
						'message'        => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
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
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
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
			return array( 'success' => false, 'source_post_id' => 0, 'target_post_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$source_post_id        = absint( $input['source_post_id'] ?? 0 );
		$target_post_id        = absint( $input['target_post_id'] ?? 0 );
		$include_page_settings = ! empty( $input['include_page_settings'] );
		$force_replace         = ! empty( $input['force_replace'] );

		if ( $source_post_id === $target_post_id ) {
			return $this->fail( $source_post_id, $target_post_id, 'invalid_payload', __( 'source_post_id and target_post_id must differ.', 'acrossai-abilities-manager' ) );
		}

		$source = Document_Repository::load_document( $source_post_id, 'read' );
		if ( is_wp_error( $source ) ) {
			return $this->fail( $source_post_id, $target_post_id, (string) $source->get_error_code(), (string) $source->get_error_message() );
		}
		$target = Document_Repository::load_document( $target_post_id, 'edit' );
		if ( is_wp_error( $target ) ) {
			return $this->fail( $source_post_id, $target_post_id, (string) $target->get_error_code(), (string) $target->get_error_message() );
		}

		if ( ! $force_replace && Document_Repository::is_document_populated( $target['data'] ) ) {
			return $this->fail( $source_post_id, $target_post_id, 'force_replace_required', __( 'Target post already has Elementor content. Pass force_replace=true to overwrite.', 'acrossai-abilities-manager' ) );
		}

		// Deep-clone the source tree with fresh IDs.
		$cloned = array();
		foreach ( $source['data'] as $element ) {
			if ( is_array( $element ) ) {
				$cloned[] = Document_Repository::reassign_subtree_ids( $element );
			}
		}

		$saved = Document_Repository::save_data( $target_post_id, $cloned, 'post' );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $source_post_id, $target_post_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		if ( $include_page_settings && ! empty( $source['page_settings'] ) ) {
			update_post_meta( $target_post_id, '_elementor_page_settings', $source['page_settings'] );
		}

		$element_count = 0;
		Document_Repository::walk_tree( $cloned, static function () use ( &$element_count ): void { ++$element_count; } );

		return array(
			'success'        => true,
			'source_post_id' => $source_post_id,
			'target_post_id' => $target_post_id,
			'element_count'  => $element_count,
			/* translators: 1: element count, 2: source, 3: target */
			'message'        => sprintf( __( 'Cloned %1$d elements from post #%2$d to post #%3$d.', 'acrossai-abilities-manager' ), $element_count, $source_post_id, $target_post_id ),
		);
	}

	/**
	 * @param int    $source
	 * @param int    $target
	 * @param string $code
	 * @param string $message
	 * @return array<string,mixed>
	 */
	private function fail( int $source, int $target, string $code, string $message ): array {
		return array(
			'success'        => false,
			'source_post_id' => $source,
			'target_post_id' => $target,
			'message'        => $message,
			'error_code'     => $code,
		);
	}
}
