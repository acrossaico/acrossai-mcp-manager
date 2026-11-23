<?php
/**
 * Feature 074 — serialize a normalized block tree back into block markup.
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
 * Stateless serialize of a block-tree array back into block markup.
 */
class Serialize_Blocks extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/serialize-blocks',
			'args' => array(
				'label'               => __( 'Serialize Blocks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Serialize a normalized block tree back into block markup. Round-trip parity with blocks/parse-content for valid input.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'blocks' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'             => array( 'blocks' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'content' => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
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
		$blocks = is_array( $input['blocks'] ?? null ) ? $input['blocks'] : array();

		foreach ( $blocks as $i => $block ) {
			$validation = self::validate_block( $block, array( (int) $i ) );
			if ( null !== $validation ) {
				return array(
					'success' => false,
					'content' => '',
					'message' => $validation,
				);
			}
		}

		return array(
			'success' => true,
			'content' => serialize_blocks( $blocks ),
			'message' => __( 'Blocks serialized.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Recursively validate a block entry against the parse_blocks shape.
	 *
	 * @param mixed $block Block payload.
	 * @param int[] $path  Current path (for error annotation).
	 * @return string|null Error message or null.
	 */
	private static function validate_block( $block, array $path ): ?string {
		if ( ! is_array( $block ) ) {
			return sprintf( 'Block at path [%s] is not an object.', implode( ',', $path ) );
		}
		if ( ! array_key_exists( 'blockName', $block ) ) {
			return sprintf( 'Block at path [%s] is missing `blockName`.', implode( ',', $path ) );
		}
		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $i => $child ) {
				$err = self::validate_block( $child, array_merge( $path, array( (int) $i ) ) );
				if ( null !== $err ) {
					return $err;
				}
			}
		}
		return null;
	}
}
