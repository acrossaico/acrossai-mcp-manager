<?php
/**
 * Clear_Overrides ability (Feature 061).
 *
 * Slug: acrossai/conflict-test-clear-overrides
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Delete every override in a single call.
 *
 * Restores every plugin's effective state to its DB-recorded state.
 * Idempotent — calling on an already-empty map returns cleared=true with
 * file_existed_before=false.
 */
class Clear_Overrides extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'acrossai/conflict-test-clear-overrides',
			'args' => array(
				'label'               => __( 'Clear Conflict-Test Overrides', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deletes the overrides map in a single call, restoring every plugin\'s effective state to its DB-recorded state.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
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
						'success'             => array( 'type' => 'boolean' ),
						'cleared'             => array( 'type' => 'boolean' ),
						'file_existed_before' => array( 'type' => 'boolean' ),
						'message'             => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'conflict-testing',
						'sub_group_label' => __( 'Conflict Testing', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute — delete the overrides file.
	 *
	 * @param array $input Ignored.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$result = Overrides_Store::instance()->clear();

		return array(
			'success'             => (bool) $result['cleared'],
			'cleared'             => (bool) $result['cleared'],
			'file_existed_before' => (bool) $result['file_existed_before'],
		);
	}
}
