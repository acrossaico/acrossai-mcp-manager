<?php
/**
 * Feature 067 — insert an Elementor widget element.
 *
 * Validates the widget type against Elementor's live widget registry
 * before writing. Combined with elementor/get-widget-controls
 * this ability supports every registered widget without per-widget
 * wrappers.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Widget_Controls;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Add any registered Elementor widget at root or nested inside a parent.
 */
class Add_Widget extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/add-widget',
			'args' => array(
				'label'               => __( 'Add Elementor Widget', 'acrossai-abilities-manager' ),
				'description'         => __( 'Insert any registered Elementor widget (free, Pro, or third-party) at root or nested inside a parent element. Widget type is validated against the live registry before writing. Use get-widget-controls first to discover valid settings keys.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'widget_type' => array( 'type' => 'string' ),
						'parent_id'   => array( 'type' => array( 'string', 'null' ) ),
						'position'    => array( 'type' => 'integer', 'minimum' => 0 ),
						'settings'    => array( 'type' => 'object' ),
						'cache_scope' => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'widget_type' ),
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
			return $this->fail( 0, (string) $check->get_error_code(), (string) $check->get_error_message() );
		}
		$post_id     = absint( $input['post_id'] ?? 0 );
		$widget_type = isset( $input['widget_type'] ) ? sanitize_key( (string) $input['widget_type'] ) : '';
		$parent_id   = ( isset( $input['parent_id'] ) && '' !== $input['parent_id'] ) ? (string) $input['parent_id'] : null;
		$position    = (int) ( $input['position'] ?? 0 );
		$settings    = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$cache_scope = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

		if ( '' === $widget_type ) {
			return $this->fail( $post_id, 'invalid_widget_type', __( 'widget_type is required.', 'acrossai-abilities-manager' ) );
		}
		if ( null === Widget_Controls::get_type( $widget_type ) ) {
			return $this->fail( $post_id, 'invalid_widget_type', __( 'Widget type is not registered on this site.', 'acrossai-abilities-manager' ) );
		}
		if ( null !== $parent_id && ! Document_Repository::is_valid_element_id( $parent_id ) ) {
			return $this->fail( $post_id, 'invalid_element_id', __( 'parent_id must be a 7-character hex string.', 'acrossai-abilities-manager' ) );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return $this->fail( $post_id, (string) $doc->get_error_code(), (string) $doc->get_error_message() );
		}

		$new_id      = Document_Repository::generate_element_id();
		$new_element = array(
			'id'         => $new_id,
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => array(),
		);

		if ( ! Document_Repository::insert_element( $doc['data'], $parent_id, $position, $new_element ) ) {
			return $this->fail( $post_id, 'element_not_found', __( 'Parent element not found in this post.', 'acrossai-abilities-manager' ) );
		}

		$saved = Document_Repository::save_data( $post_id, $doc['data'], $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $post_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}

		return array(
			'success'    => true,
			'post_id'    => $post_id,
			'element_id' => $new_id,
			'element'    => $new_element,
			/* translators: 1: widget type, 2: element id, 3: post id */
			'message'    => sprintf( __( 'Inserted %1$s widget %2$s in post #%3$d.', 'acrossai-abilities-manager' ), $widget_type, $new_id, $post_id ),
		);
	}

	/**
	 * @param int    $post_id
	 * @param string $code
	 * @param string $message
	 * @return array<string,mixed>
	 */
	private function fail( int $post_id, string $code, string $message ): array {
		return array(
			'success'    => false,
			'post_id'    => $post_id,
			'message'    => $message,
			'error_code' => $code,
		);
	}
}
