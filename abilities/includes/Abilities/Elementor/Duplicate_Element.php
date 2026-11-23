<?php
/**
 * Feature 067 — duplicate an Elementor element with fresh IDs.
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
 * Deep-clone an element and insert the clone as the next sibling of
 * the source. IDs throughout the cloned subtree are regenerated.
 */
class Duplicate_Element extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/duplicate-element',
			'args' => array(
				'label'               => __( 'Duplicate Elementor Element', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deep-clone an Elementor element (including all nested children) and insert the clone as the next sibling of the source. IDs are regenerated throughout the cloned subtree.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'element_id'  => array( 'type' => 'string' ),
						'cache_scope' => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'element_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'           => array( 'type' => 'boolean' ),
						'post_id'           => array( 'type' => 'integer' ),
						'source_element_id' => array( 'type' => 'string' ),
						'clone_element_id'  => array( 'type' => 'string' ),
						'message'           => array( 'type' => 'string' ),
						'error_code'        => array( 'type' => 'string' ),
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
			return $this->fail( 0, '', (string) $check->get_error_code(), (string) $check->get_error_message() );
		}
		$post_id     = absint( $input['post_id'] ?? 0 );
		$element_id  = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
		$cache_scope = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

		if ( ! Document_Repository::is_valid_element_id( $element_id ) ) {
			return $this->fail( $post_id, $element_id, 'invalid_element_id', __( 'element_id must be a 7-character hex string.', 'acrossai-abilities-manager' ) );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return $this->fail( $post_id, $element_id, (string) $doc->get_error_code(), (string) $doc->get_error_message() );
		}

		$source = Document_Repository::find_element_by_id( $doc['data'], $element_id );
		if ( null === $source ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Element not found.', 'acrossai-abilities-manager' ) );
		}

		// Deep-clone and regenerate every ID in the subtree.
		$clone            = Document_Repository::reassign_subtree_ids( $source['element'] );
		$clone_element_id = (string) $clone['id'];

		$parent_id = ! empty( $source['path'] ) ? (string) end( $source['path'] ) : null;
		// Find the source's sibling index inside its parent so we can insert the clone at index+1.
		$sibling_index = $this->find_sibling_index( $doc['data'], $parent_id, $element_id );

		if ( ! Document_Repository::insert_element( $doc['data'], $parent_id, $sibling_index + 1, $clone ) ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Failed to insert clone.', 'acrossai-abilities-manager' ) );
		}

		$saved = Document_Repository::save_data( $post_id, $doc['data'], $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $post_id, $element_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		return array(
			'success'           => true,
			'post_id'           => $post_id,
			'source_element_id' => $element_id,
			'clone_element_id'  => $clone_element_id,
			/* translators: 1: source, 2: clone, 3: post id */
			'message'           => sprintf( __( 'Duplicated element %1$s as %2$s on post #%3$d.', 'acrossai-abilities-manager' ), $element_id, $clone_element_id, $post_id ),
		);
	}

	/**
	 * Find the sibling index of $target_id under $parent_id (or root).
	 *
	 * @param array<int, array<string, mixed>> $elements Root tree.
	 * @param string|null                      $parent_id
	 * @param string                           $target_id
	 * @return int Sibling index (0-based), or -1 if not found.
	 */
	private function find_sibling_index( array $elements, ?string $parent_id, string $target_id ): int {
		if ( null === $parent_id ) {
			foreach ( $elements as $index => $element ) {
				if ( isset( $element['id'] ) && (string) $element['id'] === $target_id ) {
					return (int) $index;
				}
			}
			return -1;
		}
		$parent = Document_Repository::find_element_by_id( $elements, $parent_id );
		if ( null === $parent || ! isset( $parent['element']['elements'] ) || ! is_array( $parent['element']['elements'] ) ) {
			return -1;
		}
		foreach ( $parent['element']['elements'] as $index => $child ) {
			if ( isset( $child['id'] ) && (string) $child['id'] === $target_id ) {
				return (int) $index;
			}
		}
		return -1;
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
			$out['source_element_id'] = $element_id;
		}
		return $out;
	}
}
