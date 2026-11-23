<?php
/**
 * Feature 055 — list theme-registered block-template-part areas.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return every registered block-template-part `area` (header / footer /
 * sidebar / uncategorized / …) with the template parts that live in it.
 */
class List_Block_Areas extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/list-block-areas',
			'args' => array(
				'label'               => __( 'List Block Areas', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return every registered block-template-part `area` (header / footer / sidebar / uncategorized / …) with the template parts that live in it. Reads get_allowed_block_template_part_areas() + get_block_templates() for wp_template_part.', 'acrossai-abilities-manager' ),
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
						'areas'   => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'site-editor',
						'sub_group_label' => __( 'Site Editor', 'acrossai-abilities-manager' ),
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

		$areas_meta = array();
		if ( function_exists( 'get_allowed_block_template_part_areas' ) ) {
			$raw = get_allowed_block_template_part_areas();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$area                = (string) ( $entry['area'] ?? '' );
					if ( '' === $area ) {
						continue;
					}
					$areas_meta[ $area ] = array(
						'area'  => $area,
						'label' => (string) ( $entry['label'] ?? $area ),
						'parts' => array(),
					);
				}
			}
		}

		$parts = get_block_templates( array(), 'wp_template_part' );
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				$area = isset( $part->area ) ? (string) $part->area : 'uncategorized';
				if ( ! isset( $areas_meta[ $area ] ) ) {
					$areas_meta[ $area ] = array(
						'area'  => $area,
						'label' => $area,
						'parts' => array(),
					);
				}
				$areas_meta[ $area ]['parts'][] = array(
					'id'    => isset( $part->id ) ? sanitize_text_field( (string) $part->id ) : '',
					'slug'  => isset( $part->slug ) ? sanitize_title( (string) $part->slug ) : '',
					'title' => isset( $part->title ) ? sanitize_text_field( (string) $part->title ) : '',
				);
			}
		}

		return array(
			'success' => true,
			'areas'   => array_values( $areas_meta ),
			/* translators: %d: area count */
			'message' => sprintf( __( '%d block-template-part area(s) known.', 'acrossai-abilities-manager' ), count( $areas_meta ) ),
		);
	}
}
