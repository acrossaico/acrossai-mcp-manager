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
 * Schedule a recurring or one-off WP-Cron event. If "schedule" is omitted (or
 * empty), wp_schedule_single_event() is used; otherwise wp_schedule_event().
 */
class Create_Cron_Job extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/create-cron-job',
			'args' => array(
				'label'               => __( 'Create Cron Job', 'acrossai-abilities-manager' ),
				'description'         => __( 'Schedule a WP-Cron event. Pass "schedule" (e.g. hourly, daily, or any registered name) for a recurring event; omit it for a one-off via wp_schedule_single_event().', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cron',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hook'      => array( 'type' => 'string' ),
						'schedule'  => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Recurring schedule name from wp_get_schedules(). Leave empty for a one-off event.', 'acrossai-abilities-manager' ),
						),
						'timestamp' => array(
							'type'        => 'integer',
							'description' => __( 'When the event should first fire (UNIX timestamp). Defaults to now.', 'acrossai-abilities-manager' ),
						),
						'args'      => array( 'type' => 'array' ),
					),
					'required'             => array( 'hook' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'   => array( 'type' => 'boolean' ),
						'hook'      => array( 'type' => 'string' ),
						'timestamp' => array( 'type' => 'integer' ),
						'schedule'  => array( 'type' => 'string' ),
						'message'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'cron',
						'sub_group'       => 'write',
						'sub_group_label' => __( 'Write Cron Jobs', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
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

		$schedule  = sanitize_key( (string) ( $input['schedule'] ?? '' ) );
		$timestamp = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : (int) time();
		$args      = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();

		if ( '' !== $schedule ) {
			$schedules = wp_get_schedules();
			if ( ! isset( $schedules[ $schedule ] ) ) {
				return array(
					'success' => false,
					/* translators: %s: schedule name */
					'message' => sprintf( __( 'Unknown schedule "%s".', 'acrossai-abilities-manager' ), $schedule ),
				);
			}
			$result = wp_schedule_event( $timestamp, $schedule, $hook, $args, true );
		} else {
			$result = wp_schedule_single_event( $timestamp, $hook, $args, true );
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success'   => true,
			'hook'      => $hook,
			'timestamp' => $timestamp,
			'schedule'  => $schedule,
			'message'   => '' !== $schedule
				/* translators: 1: hook, 2: schedule */
				? sprintf( __( 'Scheduled "%1$s" on schedule "%2$s".', 'acrossai-abilities-manager' ), $hook, $schedule )
				/* translators: %s: hook */
				: sprintf( __( 'Scheduled one-off "%s".', 'acrossai-abilities-manager' ), $hook ),
		);
	}
}
