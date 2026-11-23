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
 * Reschedule an existing event by unscheduling the current instance and
 * registering it again with new parameters. WP-Cron has no native "update"
 * — args identify the event, so the unschedule+schedule sequence is the
 * idiomatic approach.
 */
class Update_Cron_Job extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/update-cron-job',
			'args' => array(
				'label'               => __( 'Update Cron Job', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reschedule an existing event: unschedule the original (identified by hook + old_args) and create a new one with the supplied schedule, timestamp, and args.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cron',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hook'          => array( 'type' => 'string' ),
						'old_args'      => array( 'type' => 'array' ),
						'new_schedule'  => array( 'type' => 'string' ),
						'new_timestamp' => array( 'type' => 'integer' ),
						'new_args'      => array( 'type' => 'array' ),
					),
					'required'             => array( 'hook' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'   => array( 'type' => 'boolean' ),
						'hook'      => array( 'type' => 'string' ),
						'schedule'  => array( 'type' => 'string' ),
						'timestamp' => array( 'type' => 'integer' ),
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

		$old_args = isset( $input['old_args'] ) && is_array( $input['old_args'] ) ? $input['old_args'] : array();
		$existing = wp_next_scheduled( $hook, $old_args );
		if ( false === $existing ) {
			return array(
				'success' => false,
				/* translators: %s: hook name */
				'message' => sprintf( __( 'No scheduled event for hook "%s" with the given args.', 'acrossai-abilities-manager' ), $hook ),
			);
		}

		$unscheduled = wp_unschedule_event( $existing, $hook, $old_args, true );
		if ( is_wp_error( $unscheduled ) ) {
			return array(
				'success' => false,
				'message' => $unscheduled->get_error_message(),
			);
		}

		$schedule  = sanitize_key( (string) ( $input['new_schedule'] ?? '' ) );
		$timestamp = isset( $input['new_timestamp'] ) ? (int) $input['new_timestamp'] : (int) time();
		$new_args  = isset( $input['new_args'] ) && is_array( $input['new_args'] ) ? $input['new_args'] : $old_args;

		if ( '' !== $schedule ) {
			$schedules = wp_get_schedules();
			if ( ! isset( $schedules[ $schedule ] ) ) {
				return array(
					'success' => false,
					/* translators: %s: schedule name */
					'message' => sprintf( __( 'Unknown schedule "%s".', 'acrossai-abilities-manager' ), $schedule ),
				);
			}
			$result = wp_schedule_event( $timestamp, $schedule, $hook, $new_args, true );
		} else {
			$result = wp_schedule_single_event( $timestamp, $hook, $new_args, true );
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
			'schedule'  => $schedule,
			'timestamp' => $timestamp,
			/* translators: %s: hook name */
			'message'   => sprintf( __( 'Rescheduled "%s".', 'acrossai-abilities-manager' ), $hook ),
		);
	}
}
