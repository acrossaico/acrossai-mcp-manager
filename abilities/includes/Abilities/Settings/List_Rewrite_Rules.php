<?php
/**
 * Site introspection read — persisted rewrite-rules map (Feature 063).
 *
 * Returns the value of the rewrite_rules option (regex → query template
 * map) along with a count of entries. Empty map when rewrite rules have
 * never been generated.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Settings
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Settings;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List_Rewrite_Rules ability class.
 */
class List_Rewrite_Rules extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'settings/list-rewrite-rules',
			'args' => array(
				'label'               => __( 'List Rewrite Rules', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the persisted rewrite-rules map (regex to query-template pairs) and a count of entries.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-settings',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'rules'   => array( 'type' => 'object' ),
						'count'   => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
						'sub_group'       => 'permalinks',
						'sub_group_label' => __( 'Permalinks', 'acrossai-abilities-manager' ),
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$rules = get_option( 'rewrite_rules', array() );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		return array(
			'success' => true,
			'rules'   => (object) $rules,
			'count'   => count( $rules ),
			'message' => __( 'Rewrite rules fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
