<?php
/**
 * Feature 070 — style book payload for the block registry.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return the Site Editor Style Book payload: for every registered block that
 * declares a style-book example, return the block name, title, category, and
 * the example markup.
 */
class Get_Style_Book extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/get-style-book',
			'args' => array(
				'label'               => __( 'Get Style Book', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the Site Editor Style Book payload. For every registered block that declares an example, return the block name, title, category, and example markup. Same data source the Styles → Style Book panel renders.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'blocks'  => array( 'type' => 'array' ),
						'total'   => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'block-info',
						'sub_group_label' => __( 'Block Info', 'acrossai-abilities-manager' ),
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
		unset( $input );

		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return array(
				'success' => false,
				'blocks'  => array(),
				'total'   => 0,
				'message' => __( 'WP_Block_Type_Registry is unavailable.', 'acrossai-abilities-manager' ),
			);
		}

		$registered = \WP_Block_Type_Registry::get_instance()->get_all_registered();
		$entries    = array();

		foreach ( $registered as $name => $block_type ) {
			if ( empty( $block_type->example ) || ! is_array( $block_type->example ) ) {
				continue;
			}

			$example_markup = '';
			if ( function_exists( 'render_block' ) ) {
				$rendered = render_block(
					array(
						'blockName'    => (string) $name,
						'attrs'        => (array) ( $block_type->example['attributes'] ?? array() ),
						'innerBlocks'  => (array) ( $block_type->example['innerBlocks'] ?? array() ),
						'innerHTML'    => (string) ( $block_type->example['innerHTML'] ?? '' ),
						'innerContent' => (array) ( $block_type->example['innerContent'] ?? array() ),
					)
				);
				$example_markup = is_string( $rendered ) ? $rendered : '';
			}

			$entries[] = array(
				'name'     => sanitize_text_field( (string) $name ),
				'title'    => sanitize_text_field( (string) ( $block_type->title ?? '' ) ),
				'category' => sanitize_text_field( (string) ( $block_type->category ?? '' ) ),
				'example'  => $example_markup,
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'success' => true,
			'blocks'  => $entries,
			'total'   => count( $entries ),
			/* translators: %d: number of blocks with style-book examples */
			'message' => sprintf( __( 'Returned %d block(s) with style-book examples.', 'acrossai-abilities-manager' ), count( $entries ) ),
		);
	}
}
