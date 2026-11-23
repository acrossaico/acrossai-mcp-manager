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
 * Get_Next_Cron_Run ability class (absorbed).
 */
class Get_Next_Cron_Run extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/get-next-cron-run',
			'args' => array(
				'label'               => __( 'Get Next Run Time', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the next scheduled run timestamp for a hook (and optional args) via wp_next_scheduled().', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cron',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hook' => array( 'type' => 'string' ),
						'args' => array( 'type' => 'array' ),
					),
					'required'             => array( 'hook' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'hook'          => array( 'type' => 'string' ),
						'scheduled'     => array( 'type' => 'boolean' ),
						'timestamp'     => array( 'type' => array( 'integer', 'null' ) ),
						'datetime'      => array( 'type' => array( 'string', 'null' ) ),
						'seconds_until' => array( 'type' => array( 'integer', 'null' ) ),
						'message'       => array( 'type' => 'string' ),
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
		$hook = sanitize_text_field( (string) ( $input['hook'] ?? '' ) );
		if ( '' === $hook ) {
			return array(
				'success' => false,
				'message' => __( 'hook is required.', 'acrossai-abilities-manager' ),
			);
		}

		$args      = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();
		$timestamp = wp_next_scheduled( $hook, $args );

		if ( false === $timestamp ) {
			return array(
				'success'       => true,
				'hook'          => $hook,
				'scheduled'     => false,
				'timestamp'     => null,
				'datetime'      => null,
				'seconds_until' => null,
			);
		}

		return array(
			'success'       => true,
			'hook'          => $hook,
			'scheduled'     => true,
			'timestamp'     => (int) $timestamp,
			'datetime'      => gmdate( 'c', (int) $timestamp ),
			'seconds_until' => (int) $timestamp - (int) time(),
		);
	}
}
