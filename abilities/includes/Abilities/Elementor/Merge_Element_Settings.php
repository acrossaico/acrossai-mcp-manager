<?php
/**
 * Feature 067 — deep-merge settings into a single Elementor element.
 *
 * Safer than update-element for targeted patches — modifies only the
 * specified settings keys and leaves everything else untouched. No
 * force_replace guard needed because the operation is inherently
 * additive.
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
 * Deep-merge settings into the element at element_id.
 */
class Merge_Element_Settings extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/merge-element-settings',
			'args' => array(
				'label'               => __( 'Merge Elementor Element Settings', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deep-merge new settings into a single Elementor element by ID. Only the supplied setting keys are changed; siblings and unchanged settings are preserved.', 'acrossai-abilities-manager' ),
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
						'settings'    => array( 'type' => 'object' ),
						'cache_scope' => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'element_id', 'settings' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'post_id'      => array( 'type' => 'integer' ),
						'element_id'   => array( 'type' => 'string' ),
						'element'      => array( 'type' => 'object' ),
						'changed_keys' => array( 'type' => 'array' ),
						'message'      => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
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
			return $this->fail( 0, '', (string) $check->get_error_code(), (string) $check->get_error_message() );
		}
		$post_id     = absint( $input['post_id'] ?? 0 );
		$element_id  = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
		$new_settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$cache_scope = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

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

		$merged_element             = $existing['element'];
		$prior_settings             = isset( $merged_element['settings'] ) && is_array( $merged_element['settings'] ) ? $merged_element['settings'] : array();
		$merged_element['settings'] = self::deep_merge( $prior_settings, $new_settings );
		$changed_keys               = array_keys( $new_settings );

		if ( ! Document_Repository::replace_element_by_id( $doc['data'], $element_id, $merged_element ) ) {
			return $this->fail( $post_id, $element_id, 'element_not_found', __( 'Failed to update element.', 'acrossai-abilities-manager' ) );
		}

		$saved = Document_Repository::save_data( $post_id, $doc['data'], $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $post_id, $element_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		return array(
			'success'      => true,
			'post_id'      => $post_id,
			'element_id'   => $element_id,
			'element'      => $merged_element,
			'changed_keys' => $changed_keys,
			/* translators: 1: count, 2: element id, 3: post id */
			'message'      => sprintf( __( 'Merged %1$d settings into element %2$s on post #%3$d.', 'acrossai-abilities-manager' ), count( $changed_keys ), $element_id, $post_id ),
		);
	}

	/**
	 * Deep-merge $override into $base. Arrays are merged recursively; scalars replace.
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $override
	 * @return array<string, mixed>
	 */
	private static function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
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
