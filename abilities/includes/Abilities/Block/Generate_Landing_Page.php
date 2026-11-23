<?php
/**
 * Feature 075 — generate a landing-page block tree from a small input set.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Recipe_Registry;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Recipe_Renderer;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministically build a landing-page block tree from a business_name +
 * tone + section list. Theme-neutral output.
 */
class Generate_Landing_Page extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/generate-landing-page',
			'args' => array(
				'label'               => __( 'Generate Landing Page', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deterministically generate a structured landing-page block tree from a small input set (business_name, tone, sections). Theme-neutral — no color, typography, or width overrides. Does not save the page.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'business_name' => array( 'type' => 'string' ),
						'tone'          => array( 'type' => 'string' ),
						'sections'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'recipe_id'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'business_name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'blocks'  => array( 'type' => 'array' ),
						'meta'    => array( 'type' => 'object' ),
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
		$biz  = sanitize_text_field( (string) ( $input['business_name'] ?? '' ) );
		$tone = sanitize_text_field( (string) ( $input['tone'] ?? '' ) );
		$rid  = sanitize_text_field( (string) ( $input['recipe_id'] ?? 'landing-simple' ) );

		if ( '' === $biz ) {
			return $this->failure( __( 'business_name is required.', 'acrossai-abilities-manager' ) );
		}

		$sections = is_array( $input['sections'] ?? null )
			? array_map( 'sanitize_text_field', $input['sections'] )
			: array();

		$context = array( 'business_name' => $biz, 'tone' => $tone );
		$blocks  = array();
		$used    = array();
		$warns   = array();

		if ( array() !== $sections ) {
			foreach ( $sections as $sid ) {
				$rendered = Block_Recipe_Renderer::render( $sid, $context );
				$blocks   = array_merge( $blocks, $rendered['blocks'] );
				$used[]   = $sid;
				$warns    = array_merge( $warns, $rendered['warnings'] );
			}
		} else {
			$recipe = Block_Recipe_Registry::get( $rid );
			if ( null === $recipe ) {
				return $this->failure( sprintf( 'Unknown recipe id: %s', $rid ) );
			}
			$page  = Block_Recipe_Renderer::render_page( $recipe, $context );
			$blocks = $page['blocks'];
			$used   = $page['section_ids_used'];
			$warns  = $page['warnings'];
		}

		return array(
			'success' => true,
			'blocks'  => $blocks,
			'meta'    => (object) array(
				'recipe_id'        => $rid,
				'section_ids_used' => $used,
				'warnings'         => $warns,
			),
			/* translators: %d: block count */
			'message' => sprintf( __( 'Generated %d top-level block(s).', 'acrossai-abilities-manager' ), count( $blocks ) ),
		);
	}

	/**
	 * Failure envelope.
	 *
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( string $message ): array {
		return array(
			'success' => false,
			'blocks'  => array(),
			'meta'    => (object) array(),
			'message' => $message,
		);
	}
}
