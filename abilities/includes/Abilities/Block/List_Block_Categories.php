<?php
/**
 * Feature 070 — enumerate registered block categories.
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
 * Return every registered block category with slug, title, and icon.
 */
class List_Block_Categories extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/list-block-categories',
			'args' => array(
				'label'               => __( 'List Block Categories', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return every registered block category with slug, title, and icon. Includes core, plugin, and theme-added categories via the default block-editor context.', 'acrossai-abilities-manager' ),
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
						'success'    => array( 'type' => 'boolean' ),
						'categories' => array( 'type' => 'array' ),
						'total'      => array( 'type' => 'integer' ),
						'message'    => array( 'type' => 'string' ),
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

		if ( ! function_exists( 'get_block_categories' ) ) {
			return array(
				'success'    => false,
				'categories' => array(),
				'total'      => 0,
				'message'    => __( 'get_block_categories() is unavailable.', 'acrossai-abilities-manager' ),
			);
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			$post = new \WP_Post( (object) array( 'ID' => 0, 'post_type' => 'post' ) );
		}

		$raw        = (array) get_block_categories( $post );
		$categories = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$categories[] = array(
				'slug'  => sanitize_key( (string) ( $entry['slug'] ?? '' ) ),
				'title' => sanitize_text_field( (string) ( $entry['title'] ?? '' ) ),
				'icon'  => is_string( $entry['icon'] ?? null ) ? sanitize_text_field( $entry['icon'] ) : '',
			);
		}

		return array(
			'success'    => true,
			'categories' => $categories,
			'total'      => count( $categories ),
			/* translators: %d: block category count */
			'message'    => sprintf( __( 'Returned %d block categor(y|ies).', 'acrossai-abilities-manager' ), count( $categories ) ),
		);
	}
}
