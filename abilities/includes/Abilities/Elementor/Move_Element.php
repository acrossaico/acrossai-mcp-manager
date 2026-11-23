<?php
/**
 * Feature 067 — move an Elementor element to a new parent/position.
 *
 * Atomic: remove + insert on an in-memory tree copy so callers never
 * see the intermediate state. Refuses moves whose destination lies
 * inside the source's own subtree (would create a cycle).
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
 * Move an element to a new parent/position atomically.
 */
class Move_Element extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/move-element',
			'args' => array(
				'label'               => __( 'Move Elementor Element', 'acrossai-abilities-manager' ),
				'description'         => __( 'Move an Elementor element to a new parent and sibling position. Atomic: source and destination are updated together. Refuses moves whose destination lies inside the source subtree.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'element_id'    => array( 'type' => 'string' ),
						'new_parent_id' => array( 'type' => array( 'string', 'null' ) ),
						'position'      => array( 'type' => 'integer', 'minimum' => 0 ),
						'cache_scope'   => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'element_id', 'position' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'            => array( 'type' => 'boolean' ),
						'post_id'            => array( 'type' => 'integer' ),
						'element_id'         => array( 'type' => 'string' ),
						'previous_parent_id' => array( 'type' => array( 'string', 'null' ) ),
						'new_parent_id'      => array( 'type' => array( 'string', 'null' ) ),
						'position'           => array( 'type' => 'integer' ),
						'message'            => array( 'type' => 'string' ),
						'error_code'         => array( 'type' => 'string' ),
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
		$post_id       = absint( $input['post_id'] ?? 0 );
		$element_id    = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
		$new_parent_id = ( isset( $input['new_parent_id'] ) && '' !== $input['new_parent_id'] ) ? (string) $input['new_parent_id'] : null;
		$position      = (int) ( $input['position'] ?? 0 );
		$cache_scope   = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

		if ( ! Document_Repository::is_valid_element_id( $element_id ) ) {
			return $this->fail( $post_id, $element_id, 'invalid_element_id', __( 'element_id must be a 7-character hex string.', 'acrossai-abilities-manager' ) );
		}
		if ( null !== $new_parent_id && ! Document_Repository::is_valid_element_id( $new_parent_id ) ) {
			return $this->fail( $post_id, $element_id, 'invalid_element_id', __( 'new_parent_id must be a 7-character hex string.', 'acrossai-abilities-manager' ) );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return $this->fail( $post_id, $element_id, (string) $doc->get_error_code(), (string) $doc->get_error_message() );
		}

		$source = Document_Repository::find_element_by_id( $doc['data'], $element_id );
		if ( null === $source ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Source element not found.', 'acrossai-abilities-manager' ) );
		}

		// Descendant guard: destination must not lie inside the source subtree.
		if ( null !== $new_parent_id ) {
			$dest = Document_Repository::find_element_by_id( $doc['data'], $new_parent_id );
			if ( null === $dest ) {
				return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Destination parent not found.', 'acrossai-abilities-manager' ) );
			}
			// If element_id is in dest's parent path OR dest is element_id itself, it's a cycle.
			if ( $new_parent_id === $element_id || in_array( $element_id, $dest['path'], true ) ) {
				return $this->fail( $post_id, $element_id, 'descendant_destination', __( 'Cannot move an element into its own subtree.', 'acrossai-abilities-manager' ) );
			}
		}

		$previous_parent_id = ! empty( $source['path'] ) ? (string) end( $source['path'] ) : null;

		// Atomic move: remove from current position, then insert into destination.
		$moved = Document_Repository::remove_element_by_id( $doc['data'], $element_id );
		if ( null === $moved ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Failed to remove source element.', 'acrossai-abilities-manager' ) );
		}
		if ( ! Document_Repository::insert_element( $doc['data'], $new_parent_id, $position, $moved ) ) {
			// Attempt rollback by re-inserting at original position (best-effort).
			Document_Repository::insert_element( $doc['data'], $previous_parent_id, 0, $moved );
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Failed to insert at destination.', 'acrossai-abilities-manager' ) );
		}

		$saved = Document_Repository::save_data( $post_id, $doc['data'], $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $post_id, $element_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		return array(
			'success'            => true,
			'post_id'            => $post_id,
			'element_id'         => $element_id,
			'previous_parent_id' => $previous_parent_id,
			'new_parent_id'      => $new_parent_id,
			'position'           => $position,
			/* translators: 1: element id, 2: post id */
			'message'            => sprintf( __( 'Moved element %1$s on post #%2$d.', 'acrossai-abilities-manager' ), $element_id, $post_id ),
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
