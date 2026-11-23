<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Cron
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Cron;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List_Cron_Schedules ability class (absorbed).
 */
class List_Cron_Schedules extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/list-cron-schedules',
			'args' => array(
				'label'               => __( 'List Schedules', 'acrossai-abilities-manager' ),
				'description'         => __( 'List every registered cron schedule via wp_get_schedules() — includes core schedules (hourly/twicedaily/daily/weekly), schedules added by other plugins, and persisted custom schedules.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cron',
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
						'success'   => array( 'type' => 'boolean' ),
						'schedules' => array( 'type' => 'array' ),
						'total'     => array( 'type' => 'integer' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'cron',
						'sub_group'       => 'read',
						'sub_group_label' => __( 'Read Cron Jobs', 'acrossai-abilities-manager' ),
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
		$schedules = wp_get_schedules();
		$out       = array();
		foreach ( $schedules as $name => $def ) {
			$out[] = array(
				'name'     => (string) $name,
				'interval' => isset( $def['interval'] ) ? (int) $def['interval'] : 0,
				'display'  => isset( $def['display'] ) ? (string) $def['display'] : '',
			);
		}

		return array(
			'success'   => true,
			'schedules' => $out,
			'total'     => count( $out ),
		);
	}
}
