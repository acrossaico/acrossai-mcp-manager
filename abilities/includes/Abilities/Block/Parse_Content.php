<?php
/**
 * Feature 074 — parse arbitrary block-markup into a normalized block tree.
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
 * Stateless parse of a block-markup string. No post ID required.
 */
class Parse_Content extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/parse-content',
			'args' => array(
				'label'               => __( 'Parse Content', 'acrossai-abilities-manager' ),
				'description'         => __( 'Parse an arbitrary block-markup string into a normalized block tree. Stateless — no post ID required.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'content'     => array( 'type' => 'string' ),
						'include_raw' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'     => array( 'type' => 'boolean' ),
						'blocks'      => array( 'type' => 'array' ),
						'block_count' => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'content',
						'sub_group_label' => __( 'Content', 'acrossai-abilities-manager' ),
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
		$content     = (string) ( $input['content'] ?? '' );
		$include_raw = (bool) ( $input['include_raw'] ?? false );

		$blocks = parse_blocks( $content );

		if ( $include_raw ) {
			$blocks = self::attach_raw( $blocks );
		}

		return array(
			'success'     => true,
			'blocks'      => $blocks,
			'block_count' => count( $blocks ),
			/* translators: %d: top-level block count */
			'message'     => sprintf( __( 'Parsed %d top-level block(s).', 'acrossai-abilities-manager' ), count( $blocks ) ),
		);
	}

	/**
	 * Attach serialized raw markup to each block.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function attach_raw( array $blocks ): array {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block['__raw'] = serialize_blocks( array( $block ) );
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::attach_raw( $block['innerBlocks'] );
			}
		}
		return $blocks;
	}
}
