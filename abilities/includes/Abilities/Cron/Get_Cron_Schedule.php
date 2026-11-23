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
 * Get_Cron_Schedule ability class (absorbed).
 */
class Get_Cron_Schedule extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/get-cron-schedule',
			'args' => array(
				'label'               => __( 'Get Schedule Details', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return a single schedule definition by name (interval + display) from wp_get_schedules().', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cron',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array( 'type' => 'string' ),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'schedule' => array( 'type' => array( 'object', 'null' ) ),
						'message'  => array( 'type' => 'string' ),
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
		$name = sanitize_key( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			return array(
				'success' => false,
				'message' => __( 'name is required.', 'acrossai-abilities-manager' ),
			);
		}

		$schedules = wp_get_schedules();
		if ( ! isset( $schedules[ $name ] ) ) {
			return array(
				'success'  => true,
				'schedule' => null,
				/* translators: %s: schedule name */
				'message'  => sprintf( __( 'No schedule registered under "%s".', 'acrossai-abilities-manager' ), $name ),
			);
		}

		return array(
			'success'  => true,
			'schedule' => array(
				'name'     => $name,
				'interval' => isset( $schedules[ $name ]['interval'] ) ? (int) $schedules[ $name ]['interval'] : 0,
				'display'  => isset( $schedules[ $name ]['display'] ) ? (string) $schedules[ $name ]['display'] : '',
			),
		);
	}
}
