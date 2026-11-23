<?php
/**
 * Feature 075 — generate one section from a recipe id + input.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Recipe_Renderer;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministically generate one reusable section from a recipe ID and input.
 */
class Generate_Section extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/generate-section',
			'args' => array(
				'label'               => __( 'Generate Section', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deterministically generate one reusable section from a section-recipe ID and an input payload. Returns block tree only; does not save.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'recipe_id' => array( 'type' => 'string' ),
						'input'     => array( 'type' => 'object' ),
					),
					'required'             => array( 'recipe_id' ),
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
		$rid    = sanitize_text_field( (string) ( $input['recipe_id'] ?? '' ) );
		$params = is_array( $input['input'] ?? null ) ? $input['input'] : array();

		if ( '' === $rid ) {
			return $this->failure( __( 'recipe_id is required.', 'acrossai-abilities-manager' ) );
		}

		$rendered = Block_Recipe_Renderer::render( $rid, $params );
		if ( array() === $rendered['blocks'] && ! empty( $rendered['warnings'] ) ) {
			return $this->failure( (string) $rendered['warnings'][0] );
		}

		return array(
			'success' => true,
			'blocks'  => $rendered['blocks'],
			'meta'    => (object) array( 'warnings' => $rendered['warnings'] ),
			/* translators: %d: block count */
			'message' => sprintf( __( 'Generated %d block(s).', 'acrossai-abilities-manager' ), count( $rendered['blocks'] ) ),
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
