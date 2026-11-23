<?php
/**
 * Feature 067 — delete an Elementor element by ID.
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
 * Delete the element at element_id. Populated (non-empty) or top-level
 * elements require force_delete=true to prevent accidental wipes.
 */
class Delete_Element extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/delete-element',
			'args' => array(
				'label'               => __( 'Delete Elementor Element', 'acrossai-abilities-manager' ),
				'description'         => __( 'Remove the Elementor element at the given ID. Guarded by force_delete=true for populated or top-level elements.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
						'element_id'   => array( 'type' => 'string' ),
						'force_delete' => array( 'type' => 'boolean', 'default' => false ),
						'cache_scope'  => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'element_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'removed'    => array( 'type' => 'object' ),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-elements',
						'sub_group_label' => __( 'Elements & Widgets', 'acrossai-abilities-manager' ),
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
			return $this->fail( 0, '', (string) $check->get_error_code(), (string) $check->get_error_message() );
		}
		$post_id      = absint( $input['post_id'] ?? 0 );
		$element_id   = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
		$force_delete = ! empty( $input['force_delete'] );
		$cache_scope  = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

		if ( ! Document_Repository::is_valid_element_id( $element_id ) ) {
			return $this->fail( $post_id, $element_id, 'invalid_element_id', __( 'element_id must be a 7-character hex string.', 'acrossai-abilities-manager' ) );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return $this->fail( $post_id, $element_id, (string) $doc->get_error_code(), (string) $doc->get_error_message() );
		}

		$existing = Document_Repository::find_element_by_id( $doc['data'], $element_id );
		if ( null === $existing ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Element not found in this post.', 'acrossai-abilities-manager' ) );
		}

		// Force-guard: top-level or populated (has children) elements need explicit force_delete.
		$is_top_level = array() === $existing['path'];
		$has_children = isset( $existing['element']['elements'] ) && is_array( $existing['element']['elements'] ) && count( $existing['element']['elements'] ) > 0;
		if ( ! $force_delete && ( $is_top_level || $has_children ) ) {
			return $this->fail(
				$post_id,
				$element_id,
				'force_delete_required',
				$is_top_level
					? __( 'Cannot delete a top-level element without force_delete=true.', 'acrossai-abilities-manager' )
					: __( 'Cannot delete a populated element without force_delete=true.', 'acrossai-abilities-manager' )
			);
		}

		$removed = Document_Repository::remove_element_by_id( $doc['data'], $element_id );
		if ( null === $removed ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Failed to remove element.', 'acrossai-abilities-manager' ) );
		}

		$saved = Document_Repository::save_data( $post_id, $doc['data'], $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $post_id, $element_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		return array(
			'success'    => true,
			'post_id'    => $post_id,
			'element_id' => $element_id,
			'removed'    => $removed,
			/* translators: 1: element id, 2: post id */
			'message'    => sprintf( __( 'Deleted element %1$s from post #%2$d.', 'acrossai-abilities-manager' ), $element_id, $post_id ),
		);
	}

	/**
	 * @param int    $post_id
	 * @param string $element_id
	 * @param string $code
	 * @param string $message
	 * @return array<string,mixed>
	 */
	private function fail( int $post_id, string $element_id, string $code, string $message ): array {
		$out = array(
			'success'    => false,
			'post_id'    => $post_id,
			'message'    => $message,
			'error_code' => $code,
		);
		if ( '' !== $element_id ) {
			$out['element_id'] = $element_id;
		}
		return $out;
	}
}
