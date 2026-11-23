<?php
/**
 * Feature 067 — replace an Elementor element by ID.
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
 * Replace the element at element_id with a new element payload.
 * Destructive; requires force_replace=true when replacing a populated
 * element with a materially smaller payload.
 */
class Update_Element extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/update-element',
			'args' => array(
				'label'               => __( 'Update Elementor Element', 'acrossai-abilities-manager' ),
				'description'         => __( 'Replace the Elementor element at the given ID with a new element payload. Guarded by force_replace to prevent silent wipes.', 'acrossai-abilities-manager' ),
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
						'element'       => array( 'type' => 'object' ),
						'force_replace' => array( 'type' => 'boolean', 'default' => false ),
						'cache_scope'   => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'element_id', 'element' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'element'    => array( 'type' => 'object' ),
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
		$post_id       = absint( $input['post_id'] ?? 0 );
		$element_id    = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
		$new_element   = isset( $input['element'] ) && is_array( $input['element'] ) ? $input['element'] : array();
		$force_replace = ! empty( $input['force_replace'] );
		$cache_scope   = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

		if ( ! Document_Repository::is_valid_element_id( $element_id ) ) {
			return $this->fail( $post_id, $element_id, 'invalid_element_id', __( 'element_id must be a 7-character hex string.', 'acrossai-abilities-manager' ) );
		}
		if ( array() === $new_element ) {
			return $this->fail( $post_id, $element_id, 'invalid_payload', __( 'element payload is required.', 'acrossai-abilities-manager' ) );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return $this->fail( $post_id, $element_id, (string) $doc->get_error_code(), (string) $doc->get_error_message() );
		}

		$existing = Document_Repository::find_element_by_id( $doc['data'], $element_id );
		if ( null === $existing ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Element not found in this post.', 'acrossai-abilities-manager' ) );
		}

		// Force-guard: refuse when the replacement is materially smaller (fewer children or empty settings)
		// than the existing element unless the caller explicitly opts in.
		if ( ! $force_replace && $this->is_materially_smaller( $existing['element'], $new_element ) ) {
			return $this->fail(
				$post_id,
				$element_id,
				'force_replace_required',
				__( 'Replacement payload is materially smaller than the existing element. Pass force_replace=true to proceed.', 'acrossai-abilities-manager' )
			);
		}

		// Preserve the element ID.
		$new_element['id'] = $element_id;

		if ( ! Document_Repository::replace_element_by_id( $doc['data'], $element_id, $new_element ) ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Failed to replace element.', 'acrossai-abilities-manager' ) );
		}

		$saved = Document_Repository::save_data( $post_id, $doc['data'], $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $post_id, $element_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		return array(
			'success'    => true,
			'post_id'    => $post_id,
			'element_id' => $element_id,
			'element'    => $new_element,
			/* translators: 1: element id, 2: post id */
			'message'    => sprintf( __( 'Updated element %1$s on post #%2$d.', 'acrossai-abilities-manager' ), $element_id, $post_id ),
		);
	}

	/**
	 * True when the replacement is likely a silent wipe.
	 *
	 * @param array<string, mixed> $existing
	 * @param array<string, mixed> $replacement
	 * @return bool
	 */
	private function is_materially_smaller( array $existing, array $replacement ): bool {
		$existing_children    = isset( $existing['elements'] ) && is_array( $existing['elements'] ) ? count( $existing['elements'] ) : 0;
		$replacement_children = isset( $replacement['elements'] ) && is_array( $replacement['elements'] ) ? count( $replacement['elements'] ) : 0;
		if ( $existing_children > 0 && $replacement_children < $existing_children ) {
			return true;
		}
		$existing_settings    = isset( $existing['settings'] ) && is_array( $existing['settings'] ) ? count( $existing['settings'] ) : 0;
		$replacement_settings = isset( $replacement['settings'] ) && is_array( $replacement['settings'] ) ? count( $replacement['settings'] ) : 0;
		if ( $existing_settings > 0 && $replacement_settings < ( $existing_settings / 2 ) ) {
			return true;
		}
		return false;
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
