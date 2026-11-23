<?php
/**
 * Feature 067 — convenience wrapper: build native Nested Tabs where each
 * tab contains a Posts widget.
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
 * Insert a nested-tabs widget with one Posts widget per tab. Higher-order
 * shortcut that composes several element types.
 */
class Add_Post_Tabs extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/add-post-tabs',
			'args' => array(
				'label'               => __( 'Add Elementor Post Tabs', 'acrossai-abilities-manager' ),
				'description'         => __( 'Insert a Nested Tabs widget where each tab contains a native Posts widget. Each tab can filter by taxonomy term or a custom query.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'parent_id' => array( 'type' => array( 'string', 'null' ) ),
						'position'  => array( 'type' => 'integer', 'minimum' => 0 ),
						'tabs'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title'    => array( 'type' => 'string' ),
									'taxonomy' => array( 'type' => 'string' ),
									'term_id'  => array( 'type' => 'integer' ),
									'query'    => array( 'type' => 'object' ),
								),
							),
						),
					),
					'required'             => array( 'post_id', 'tabs' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'tab_count'  => array( 'type' => 'integer' ),
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
			return array( 'success' => false, 'post_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}

		$post_id   = absint( $input['post_id'] ?? 0 );
		$parent_id = ( isset( $input['parent_id'] ) && '' !== $input['parent_id'] ) ? (string) $input['parent_id'] : null;
		$position  = (int) ( $input['position'] ?? 0 );
		$tabs      = isset( $input['tabs'] ) && is_array( $input['tabs'] ) ? array_values( $input['tabs'] ) : array();

		if ( empty( $tabs ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => __( 'At least one tab is required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => (string) $doc->get_error_message(), 'error_code' => (string) $doc->get_error_code() );
		}

		// Build nested-tabs element with one Posts widget per tab.
		$tabs_id           = Document_Repository::generate_element_id();
		$tab_items         = array();
		$tab_content_items = array();
		foreach ( $tabs as $index => $tab_input ) {
			if ( ! is_array( $tab_input ) ) {
				continue;
			}
			$tab_item_id = Document_Repository::generate_element_id();
			$tab_items[] = array( '_id' => $tab_item_id, 'tab_title' => (string) ( $tab_input['title'] ?? 'Tab ' . ( $index + 1 ) ) );

			$posts_settings = array();
			if ( ! empty( $tab_input['taxonomy'] ) && ! empty( $tab_input['term_id'] ) ) {
				$posts_settings['query_' . $tab_input['taxonomy'] . '_ids'] = array( (int) $tab_input['term_id'] );
			}
			if ( ! empty( $tab_input['query'] ) && is_array( $tab_input['query'] ) ) {
				$posts_settings = array_merge( $posts_settings, $tab_input['query'] );
			}
			$tab_content_items[] = array(
				'id'         => Document_Repository::generate_element_id(),
				'elType'     => 'container',
				'settings'   => array( 'content_width' => 'full' ),
				'isInner'    => true,
				'elements'   => array(
					array(
						'id'         => Document_Repository::generate_element_id(),
						'elType'     => 'widget',
						'widgetType' => 'posts',
						'settings'   => $posts_settings,
						'elements'   => array(),
					),
				),
			);
		}

		$nested_tabs = array(
			'id'         => $tabs_id,
			'elType'     => 'widget',
			'widgetType' => 'nested-tabs',
			'settings'   => array(
				'tabs'                     => $tab_items,
				'horizontal_scroll'        => 'disable',
				'tabs_direction'           => 'top',
				'tabs_alignment'           => 'flex-start',
			),
			'elements'   => $tab_content_items,
		);

		if ( ! Document_Repository::insert_element( $doc['data'], $parent_id, $position, $nested_tabs ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => __( 'Failed to insert Nested Tabs element.', 'acrossai-abilities-manager' ), 'error_code' => 'element_not_found' );
		}
		$saved = Document_Repository::save_data( $post_id, $doc['data'], 'post' );
		if ( is_wp_error( $saved ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => (string) $saved->get_error_message(), 'error_code' => (string) $saved->get_error_code() );
		}

		return array(
			'success'    => true,
			'post_id'    => $post_id,
			'element_id' => $tabs_id,
			'tab_count'  => count( $tab_items ),
			/* translators: 1: count, 2: element id, 3: post id */
			'message'    => sprintf( __( 'Inserted post-tabs widget %2$s with %1$d tabs on post #%3$d.', 'acrossai-abilities-manager' ), count( $tab_items ), $tabs_id, $post_id ),
		);
	}
}
