<?php
/**
 * Get_Overrides ability (Feature 061).
 *
 * Slug: acrossai/conflict-test-get-overrides
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
 * Return the current override map plus the mu-plugin mechanism status.
 *
 * Auto-prunes orphaned entries on read (FR-021).
 */
class Get_Overrides extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'acrossai/conflict-test-get-overrides',
			'args' => array(
				'label'               => __( 'Get Conflict-Test Overrides', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns the current per-plugin override map and reports whether the underlying mu-plugin mechanism is deployed, missing, or stale.', 'acrossai-abilities-manager' ),
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
						'overrides'        => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'boolean' ),
						),
						'mu_plugin_status' => array(
							'type' => 'string',
							'enum' => array( 'deployed', 'missing', 'stale' ),
						),
						'parse_error'      => array(
							'type' => array( 'string', 'null' ),
						),
					),
					'required'             => array( 'overrides', 'mu_plugin_status', 'parse_error' ),
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
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute — read the overrides map (auto-prunes) and report mu-plugin status.
	 *
	 * @param array $input Ignored.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$store = Overrides_Store::instance();
		$read  = $store->read();

		return array(
			'overrides'        => (object) $read['overrides'],
			'mu_plugin_status' => $store->mu_plugin_status(),
			'parse_error'      => $read['parse_error'],
		);
	}
}
