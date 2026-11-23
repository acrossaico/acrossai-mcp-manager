<?php
/**
 * Feature 075 — enumerate registered page recipes.
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
 * Return registered full-page recipes.
 */
class List_Page_Recipes extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/list-page-recipes',
			'args' => array(
				'label'               => __( 'List Page Recipes', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return every registered full-page recipe with id, title, description, section_slugs, and input_shape. Filter-extensible via acrossai_block_recipes.', 'acrossai-abilities-manager' ),
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
		$recipes = Block_Recipe_Registry::all( Block_Recipe_Registry::KIND_PAGE );
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
			'message' => sprintf( __( 'Returned %d page recipe(s).', 'acrossai-abilities-manager' ), count( $recipes ) ),
		);
	}
}
