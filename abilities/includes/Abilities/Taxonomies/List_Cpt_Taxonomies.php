<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Taxonomies
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Taxonomies;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return the taxonomies attached to a given post type via get_object_taxonomies().
 */
class List_Cpt_Taxonomies extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'taxonomies/list-cpt-taxonomies',
			'args' => array(
				'label'               => __( 'Get CPT Taxonomies', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the taxonomies attached to a given post type via get_object_taxonomies( $post_type, "objects" ).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-taxonomies',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'taxonomies' => array( 'type' => 'array' ),
						'total'      => array( 'type' => 'integer' ),
						'message'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'taxonomies',
						'sub_group_label' => __( 'Taxonomies', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return array(
				'success' => false,
				/* translators: %s: post type */
				'message' => sprintf( __( 'Unknown post type "%s".', 'acrossai-abilities-manager' ), $post_type ),
			);
		}

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$out        = array();
		foreach ( $taxonomies as $slug => $obj ) {
			$out[] = array(
				'slug'         => (string) $slug,
				'label'        => isset( $obj->label ) ? (string) $obj->label : (string) $slug,
				'hierarchical' => (bool) $obj->hierarchical,
				'public'       => (bool) $obj->public,
				'show_in_rest' => (bool) $obj->show_in_rest,
				'rest_base'    => isset( $obj->rest_base ) && $obj->rest_base ? (string) $obj->rest_base : (string) $slug,
			);
		}

		return array(
			'success'    => true,
			'taxonomies' => $out,
			'total'      => count( $out ),
		);
	}
}
