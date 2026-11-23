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
 * Delete a single scheduled event. WP-Cron identifies events by hook + args +
 * timestamp, so the caller may supply timestamp explicitly or rely on
 * wp_next_scheduled() to find the next instance.
 */
class Delete_Cron_Job extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/delete-cron-job',
			'args' => array(
				'label'               => __( 'Delete Cron Job', 'acrossai-abilities-manager' ),
				'description'         => __( 'Unschedule a single event via wp_unschedule_event(). If timestamp is omitted, the next scheduled run for the hook+args is used.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cron',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hook'      => array( 'type' => 'string' ),
						'timestamp' => array( 'type' => 'integer' ),
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
						'message'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'cron',
						'sub_group'       => 'delete',
						'sub_group_label' => __( 'Delete Cron Jobs', 'acrossai-abilities-manager' ),
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
		$timestamp = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : 0;

		if ( $timestamp <= 0 ) {
			$timestamp = (int) wp_next_scheduled( $hook, $args );
			if ( $timestamp <= 0 ) {
				return array(
					'success' => false,
					/* translators: %s: hook name */
					'message' => sprintf( __( 'No scheduled event for hook "%s" with the given args.', 'acrossai-abilities-manager' ), $hook ),
				);
			}
		}

		$result = wp_unschedule_event( $timestamp, $hook, $args, true );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}
		if ( false === $result ) {
			return array(
				'success' => false,
				'message' => __( 'Could not unschedule the event.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success'   => true,
			'hook'      => $hook,
			'timestamp' => $timestamp,
			/* translators: 1: hook, 2: timestamp */
			'message'   => sprintf( __( 'Unscheduled "%1$s" at %2$d.', 'acrossai-abilities-manager' ), $hook, $timestamp ),
		);
	}
}
