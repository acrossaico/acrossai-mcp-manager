<?php
/**
 * Feature 075 — enumerate registered dynamic query-section recipes.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Recipe_Registry;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return registered dynamic query-section recipes.
 */
class List_Query_Section_Recipes extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/list-query-section-recipes',
			'args' => array(
				'label'               => __( 'List Query Section Recipes', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return every registered dynamic core/query recipe with id, title, description, input_shape, post_type_defaults, and preview_blocks. Filter-extensible via acrossai_block_recipes.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'keyword' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'recipes' => array( 'type' => 'array' ),
						'total'   => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'generation',
						'sub_group_label' => __( 'Generation', 'acrossai-abilities-manager' ),
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$keyword = strtolower( sanitize_text_field( (string) ( $input['keyword'] ?? '' ) ) );
		$recipes = Block_Recipe_Registry::all( Block_Recipe_Registry::KIND_QUERY_SECTION );
		if ( '' !== $keyword ) {
			$recipes = array_values( array_filter(
				$recipes,
				static function ( array $r ) use ( $keyword ): bool {
					$hay = strtolower( (string) ( $r['title'] ?? '' ) . ' ' . (string) ( $r['description'] ?? '' ) );
					return false !== strpos( $hay, $keyword );
				}
			) );
		}

		return array(
			'success' => true,
			'recipes' => $recipes,
			'total'   => count( $recipes ),
			/* translators: %d: recipe count */
			'message' => sprintf( __( 'Returned %d query section recipe(s).', 'acrossai-abilities-manager' ), count( $recipes ) ),
		);
	}
}
