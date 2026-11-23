<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Cache
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Cache;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Flush_Rewrite_Rules ability class (absorbed).
 */
class Flush_Rewrite_Rules extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cache/flush-rewrite-rules',
			'args' => array(
				'label'               => __( 'Flush Rewrite Rules', 'acrossai-abilities-manager' ),
				'description'         => __( 'Flushes WordPress rewrite rules via flush_rewrite_rules(). Use hard=true (default) to also regenerate the .htaccess file, or hard=false for an in-memory-only rebuild.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cache',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array( 'hard' => true ),
					'properties'           => array(
						'hard' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'true regenerates .htaccess (hard flush); false rebuilds only the in-memory rules (soft flush).', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'hard'    => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'hard', 'message' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
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
		$hard = isset( $input['hard'] ) ? (bool) $input['hard'] : true;

		flush_rewrite_rules( $hard );

		return array(
			'success' => true,
			'hard'    => $hard,
			'message' => $hard
				? __( 'Rewrite rules flushed (hard — .htaccess regenerated).', 'acrossai-abilities-manager' )
				: __( 'Rewrite rules flushed (soft — in-memory only).', 'acrossai-abilities-manager' ),
		);
	}
}
