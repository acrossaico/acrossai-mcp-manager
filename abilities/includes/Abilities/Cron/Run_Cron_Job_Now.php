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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Cron_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Fire a hook synchronously via do_action(). To avoid turning this into a
 * blanket action runner, we first verify the hook appears in the cron array
 * (i.e. it is genuinely a registered cron event).
 */
class Run_Cron_Job_Now extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/run-cron-job-now',
			'args' => array(
				'label'               => __( 'Run Cron Job Now', 'acrossai-abilities-manager' ),
				'description'         => __( 'Fire a scheduled cron hook synchronously via do_action(). The hook must be present in the cron array — this is not a generic do_action() runner.', 'acrossai-abilities-manager' ),
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
						'success'  => array( 'type' => 'boolean' ),
						'hook'     => array( 'type' => 'string' ),
						'duration' => array( 'type' => 'number' ),
						'message'  => array( 'type' => 'string' ),
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

		$args    = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();
		$is_cron = false;
		foreach ( Cron_Helpers::flatten_events() as $event ) {
			if ( $event['hook'] === $hook ) {
				$is_cron = true;
				break;
			}
		}
		if ( ! $is_cron ) {
			return array(
				'success' => false,
				/* translators: %s: hook name */
				'message' => sprintf( __( 'Hook "%s" is not a registered cron event — refusing to fire it.', 'acrossai-abilities-manager' ), $hook ),
			);
		}

		$started = microtime( true );
		do_action_ref_array( $hook, $args );
		$duration = round( microtime( true ) - $started, 4 );

		return array(
			'success'  => true,
			'hook'     => $hook,
			'duration' => $duration,
			/* translators: 1: hook, 2: duration seconds */
			'message'  => sprintf( __( 'Fired "%1$s" in %2$.4fs.', 'acrossai-abilities-manager' ), $hook, $duration ),
		);
	}
}
