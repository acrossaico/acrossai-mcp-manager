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
 * List terms in any taxonomy via the core REST endpoint GET /wp/v2/{rest_base}.
 * The taxonomy must have show_in_rest=true and a rest_base.
 */
class List_Terms extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'taxonomies/list-terms',
			'args' => array(
				'label'               => __( 'List Terms', 'acrossai-abilities-manager' ),
				'description'         => __( 'List terms in a taxonomy via the core REST endpoint GET /wp/v2/{rest_base}.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-taxonomies',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => array( 'type' => 'string' ),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 10,
						),
						'search'   => array( 'type' => 'string' ),
						'parent'   => array( 'type' => 'integer' ),
						'orderby'  => array(
							'type'    => 'string',
							'default' => 'name',
						),
						'order'    => array(
							'type'    => 'string',
							'enum'    => array( 'asc', 'desc' ),
							'default' => 'asc',
						),
					),
					'required'             => array( 'taxonomy' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'terms'   => array( 'type' => 'array' ),
						'total'   => array( 'type' => 'integer' ),
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
		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		$check    = Taxonomy_Routes::rest_base( $taxonomy );
		if ( is_wp_error( $check ) ) {
			return array(
				'success' => false,
				'message' => $check->get_error_message(),
			);
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );

		$filters = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);
		if ( ! empty( $input['search'] ) ) {
			$filters['search'] = sanitize_text_field( (string) $input['search'] );
		}
		if ( isset( $input['parent'] ) ) {
			$filters['parent'] = (int) $input['parent'];
		}
		if ( ! empty( $input['orderby'] ) ) {
			$filters['orderby'] = sanitize_key( (string) $input['orderby'] );
		}
		$filters['order'] = strtolower( (string) ( $input['order'] ?? 'asc' ) ) === 'desc' ? 'DESC' : 'ASC';

		$items = get_terms(
			array_merge(
				$filters,
				array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
				)
			)
		);
		if ( is_wp_error( $items ) ) {
			return Term_Formatter::error_from( $items, __( 'Could not list terms.', 'acrossai-abilities-manager' ) );
		}

		$total = (int) wp_count_terms( $filters );

		$formatted = array_values(
			array_map(
				array( Term_Formatter::class, 'term_to_array' ),
				array_filter(
					(array) $items,
					static function ( $t ): bool {
						return $t instanceof \WP_Term;
					}
				)
			)
		);

		return array(
			'success' => true,
			'terms'   => $formatted,
			'total'   => $total,
		);
	}
}
