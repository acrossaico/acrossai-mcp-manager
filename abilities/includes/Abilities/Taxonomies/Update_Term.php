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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Term ability class (absorbed).
 */
class Update_Term extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'taxonomies/update-term',
			'args' => array(
				'label'               => __( 'Update Term', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update a term in a taxonomy via POST /wp/v2/{rest_base}/{id}.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-taxonomies',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy'    => array( 'type' => 'string' ),
						'id'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'taxonomy', 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'term'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'terms',
						'sub_group_label' => __( 'Terms', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'input_flags'  => Slash_Input::meta_flags(),
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
		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		$check    = Taxonomy_Routes::rest_base( $taxonomy );
		if ( is_wp_error( $check ) ) {
			return array(
				'success' => false,
				'message' => $check->get_error_message(),
			);
		}
		$id = (int) ( $input['id'] ?? 0 );
		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A valid id is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! ( get_term( $id, $taxonomy ) instanceof \WP_Term ) ) {
			return array(
				'success' => false,
				'message' => __( 'Term not found.', 'acrossai-abilities-manager' ),
			);
		}

		$args = array();
		if ( isset( $input['name'] ) ) {
			$args['name'] = sanitize_text_field( (string) $input['name'] );
		}
		if ( isset( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = (string) $input['description'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		if ( empty( $args ) ) {
			return array(
				'success' => false,
				'message' => __( 'At least one field to update is required.', 'acrossai-abilities-manager' ),
			);
		}

		$result = wp_update_term( $id, $taxonomy, Slash_Input::slash( $args, $input ) );
		if ( is_wp_error( $result ) ) {
			return Term_Formatter::error_from(
				$result,
				/* translators: %d: term ID */
				sprintf( __( 'Could not update term #%d.', 'acrossai-abilities-manager' ), $id )
			);
		}

		$term = get_term( $id, $taxonomy );
		return array(
			'success' => true,
			'term'    => $term instanceof \WP_Term ? Term_Formatter::term_to_array( $term ) : array(),
			/* translators: %d: term ID */
			'message' => sprintf( __( 'Updated term #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
