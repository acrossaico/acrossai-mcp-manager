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
 * List taxonomies via the core REST endpoint GET /wp/v2/taxonomies.
 */
class List_Taxonomies extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'taxonomies/list-taxonomies',
			'args' => array(
				'label'               => __( 'List Taxonomies', 'acrossai-abilities-manager' ),
				'description'         => __( 'List registered taxonomies via the core REST endpoint GET /wp/v2/taxonomies.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-taxonomies',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'type' => array(
							'type'        => 'string',
							'description' => __( 'Optional: restrict to taxonomies attached to a specific post type.', 'acrossai-abilities-manager' ),
						),
					),
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
		$args = array( 'show_in_rest' => true );
		if ( ! empty( $input['type'] ) ) {
			$args['object_type'] = array( sanitize_key( (string) $input['type'] ) );
		}

		$objects = get_taxonomies( $args, 'objects' );

		$formatted = array_values(
			array_map(
				array( Term_Formatter::class, 'taxonomy_to_array' ),
				$objects
			)
		);

		return array(
			'success'    => true,
			'taxonomies' => $formatted,
			'total'      => count( $formatted ),
		);
	}
}
