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
 * Get_Taxonomy ability class (absorbed).
 */
class Get_Taxonomy extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'taxonomies/get-taxonomy',
			'args' => array(
				'label'               => __( 'Get Taxonomy', 'acrossai-abilities-manager' ),
				'description'         => __( 'Fetch a single taxonomy via GET /wp/v2/taxonomies/{taxonomy}.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-taxonomies',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => array( 'type' => 'string' ),
					),
					'required'             => array( 'taxonomy' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'taxonomy' => array( 'type' => 'object' ),
						'message'  => array( 'type' => 'string' ),
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
		$tax = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		if ( '' === $tax ) {
			return array(
				'success' => false,
				'message' => __( 'taxonomy is required.', 'acrossai-abilities-manager' ),
			);
		}

		$check = Taxonomy_Routes::rest_base( $tax );
		if ( is_wp_error( $check ) ) {
			return array(
				'success' => false,
				'message' => $check->get_error_message(),
			);
		}

		$obj = get_taxonomy( $tax );
		if ( ! ( $obj instanceof \WP_Taxonomy ) ) {
			return array(
				'success' => false,
				'message' => __( 'Taxonomy not found.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success'  => true,
			'taxonomy' => Term_Formatter::taxonomy_to_array( $obj ),
		);
	}
}
