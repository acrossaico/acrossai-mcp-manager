<?php
/**
 * Feature 067 — search Elementor elements by type / widget / text.
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
 * Search elements in a post's tree by elType, widgetType, or contains-text.
 * Read-only, idempotent.
 */
class Find_Elements extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/find-elements',
			'args' => array(
				'label'               => __( 'Find Elementor Elements', 'acrossai-abilities-manager' ),
				'description'         => __( 'Search Elementor elements in a post by element type, widget type, or text contained in the serialised settings. Returns matches with their parent-ID paths.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
						'element_type' => array( 'type' => 'string', 'enum' => array( 'container', 'widget', 'section', 'column' ) ),
						'widget_type'  => array( 'type' => 'string' ),
						'contains'     => array( 'type' => 'string' ),
						'include_path' => array( 'type' => 'boolean', 'default' => true ),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'post_id'  => array( 'type' => 'integer' ),
						'elements' => array( 'type' => 'array' ),
						'count'    => array( 'type' => 'integer' ),
						'message'  => array( 'type' => 'string' ),
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
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
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
			return array(
				'success'    => false,
				'post_id'    => 0,
				'message'    => (string) $check->get_error_message(),
				'error_code' => (string) $check->get_error_code(),
			);
		}
		$post_id      = absint( $input['post_id'] ?? 0 );
		$element_type = isset( $input['element_type'] ) ? sanitize_key( (string) $input['element_type'] ) : '';
		$widget_type  = isset( $input['widget_type'] ) ? sanitize_key( (string) $input['widget_type'] ) : '';
		$contains     = isset( $input['contains'] ) ? (string) $input['contains'] : '';
		$include_path = isset( $input['include_path'] ) ? (bool) $input['include_path'] : true;

		$doc = Document_Repository::load_document( $post_id, 'read' );
		if ( is_wp_error( $doc ) ) {
			return array(
				'success'    => false,
				'post_id'    => $post_id,
				'message'    => (string) $doc->get_error_message(),
				'error_code' => (string) $doc->get_error_code(),
			);
		}

		$contains_lower = '' !== $contains ? strtolower( $contains ) : '';
		$matches        = Document_Repository::find_elements_where(
			$doc['data'],
			static function ( array $element ) use ( $element_type, $widget_type, $contains_lower ): bool {
				if ( '' !== $element_type && ( ! isset( $element['elType'] ) || (string) $element['elType'] !== $element_type ) ) {
					return false;
				}
				if ( '' !== $widget_type && ( ! isset( $element['widgetType'] ) || (string) $element['widgetType'] !== $widget_type ) ) {
					return false;
				}
				if ( '' !== $contains_lower ) {
					$settings_json = isset( $element['settings'] ) && is_array( $element['settings'] )
						? (string) wp_json_encode( $element['settings'] )
						: '';
					if ( false === strpos( strtolower( $settings_json ), $contains_lower ) ) {
						return false;
					}
				}
				return true;
			}
		);

		$results = array();
		foreach ( $matches as $match ) {
			$entry = array( 'element' => $match['element'] );
			if ( $include_path ) {
				$entry['path'] = $match['path'];
			}
			$results[] = $entry;
		}

		return array(
			'success'  => true,
			'post_id'  => $post_id,
			'elements' => $results,
			'count'    => count( $results ),
			/* translators: 1: match count, 2: post id */
			'message'  => sprintf( __( 'Found %1$d elements in post #%2$d.', 'acrossai-abilities-manager' ), count( $results ), $post_id ),
		);
	}
}
