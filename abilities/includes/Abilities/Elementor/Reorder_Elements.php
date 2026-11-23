<?php
/**
 * Feature 067 — reorder direct children of a parent (or root).
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
 * Reorder direct children of a parent (or root children if parent_id is null).
 * Children not mentioned in ordered_element_ids are appended in their original order.
 */
class Reorder_Elements extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/reorder-elements',
			'args' => array(
				'label'               => __( 'Reorder Elementor Elements', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reorder the direct children of a parent (or root children when parent_id is null). Children not listed in ordered_element_ids retain their prior relative order and are appended after.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'             => array( 'type' => 'integer', 'minimum' => 1 ),
						'parent_id'           => array( 'type' => array( 'string', 'null' ) ),
						'ordered_element_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'cache_scope'         => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'ordered_element_ids' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'post_id'    => array( 'type' => 'integer' ),
						'parent_id'  => array( 'type' => array( 'string', 'null' ) ),
						'new_order'  => array( 'type' => 'array' ),
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
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
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
			return $this->fail( 0, null, (string) $check->get_error_code(), (string) $check->get_error_message() );
		}
		$post_id     = absint( $input['post_id'] ?? 0 );
		$parent_id   = ( isset( $input['parent_id'] ) && '' !== $input['parent_id'] ) ? (string) $input['parent_id'] : null;
		$ordered_ids = isset( $input['ordered_element_ids'] ) && is_array( $input['ordered_element_ids'] )
			? array_map( 'strval', $input['ordered_element_ids'] )
			: array();
		$cache_scope = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

		if ( null !== $parent_id && ! Document_Repository::is_valid_element_id( $parent_id ) ) {
			return $this->fail( $post_id, $parent_id, 'invalid_element_id', __( 'parent_id must be a 7-character hex string.', 'acrossai-abilities-manager' ) );
		}
		if ( empty( $ordered_ids ) ) {
			return $this->fail( $post_id, $parent_id, 'invalid_payload', __( 'ordered_element_ids must be a non-empty array.', 'acrossai-abilities-manager' ) );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return $this->fail( $post_id, $parent_id, (string) $doc->get_error_code(), (string) $doc->get_error_message() );
		}

		$result = Document_Repository::reorder_children( $doc['data'], $parent_id, $ordered_ids );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $post_id, $parent_id, (string) $result->get_error_code(), (string) $result->get_error_message() );
		}

		$saved = Document_Repository::save_data( $post_id, $doc['data'], $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $post_id, $parent_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		// Read back the effective order so the response reflects the true state (including appended leftovers).
		$new_order = array();
		if ( null === $parent_id ) {
			foreach ( $doc['data'] as $child ) {
				if ( isset( $child['id'] ) ) {
					$new_order[] = (string) $child['id'];
				}
			}
		} else {
			$parent = Document_Repository::find_element_by_id( $doc['data'], $parent_id );
			if ( null !== $parent && isset( $parent['element']['elements'] ) && is_array( $parent['element']['elements'] ) ) {
				foreach ( $parent['element']['elements'] as $child ) {
					if ( isset( $child['id'] ) ) {
						$new_order[] = (string) $child['id'];
					}
				}
			}
		}

		return array(
			'success'   => true,
			'post_id'   => $post_id,
			'parent_id' => $parent_id,
			'new_order' => $new_order,
			/* translators: 1: count, 2: post id */
			'message'   => sprintf( __( 'Reordered %1$d children on post #%2$d.', 'acrossai-abilities-manager' ), count( $new_order ), $post_id ),
		);
	}

	/**
	 * @param int         $post_id
	 * @param string|null $parent_id
	 * @param string      $code
	 * @param string      $message
	 * @return array<string,mixed>
	 */
	private function fail( int $post_id, ?string $parent_id, string $code, string $message ): array {
		return array(
			'success'    => false,
			'post_id'    => $post_id,
			'parent_id'  => $parent_id,
			'message'    => $message,
			'error_code' => $code,
		);
	}
}
