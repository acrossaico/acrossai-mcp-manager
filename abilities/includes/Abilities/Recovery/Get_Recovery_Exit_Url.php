<?php
/**
 * Feature 059 — Get recovery-mode exit URL ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.17
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Recovery_Mode;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the admin-clickable URL that exits WordPress Recovery Mode when
 * followed inside an active recovery session. Cannot programmatically exit —
 * WP core guards the exit action with both a cookie (from the recovery-mode
 * entry link) and a nonce; a normal admin REST call can't satisfy those.
 * This ability returns the URL so an admin (or an agent driving a browser)
 * can follow it.
 */
class Get_Recovery_Exit_Url extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'recovery/get-recovery-exit-url',
			'args' => array(
				'label'               => __( 'Get Recovery Mode Exit URL', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns the admin-clickable URL that exits WordPress Recovery Mode when followed inside an active recovery session. WP core does not expose a programmatic exit API (the action is cookie- and nonce-guarded); this ability returns the URL so an admin — or an agent driving a browser — can follow it. Returns null when the site is not in recovery mode.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-recovery',
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
						'success'          => array( 'type' => 'boolean' ),
						'in_recovery_mode' => array( 'type' => 'boolean' ),
						'exit_url'         => array( 'type' => array( 'string', 'null' ) ),
						'note'             => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'in_recovery_mode', 'exit_url', 'note' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'recovery',
						'sub_group_label' => __( 'Recovery Mode', 'acrossai-abilities-manager' ),
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
		$in_recovery = function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode();

		if ( ! $in_recovery ) {
			return array(
				'success'          => true,
				'in_recovery_mode' => false,
				'exit_url'         => null,
				'note'             => __( 'The site is not currently in recovery mode. Exit URL is only meaningful during an active recovery session.', 'acrossai-abilities-manager' ),
			);
		}

		$action  = class_exists( 'WP_Recovery_Mode' ) ? WP_Recovery_Mode::EXIT_ACTION : 'exit_recovery_mode';
		$url     = wp_nonce_url(
			add_query_arg( 'action', $action, admin_url() ),
			$action
		);

		return array(
			'success'          => true,
			'in_recovery_mode' => true,
			'exit_url'         => (string) $url,
			'note'             => __( 'Follow this URL in a browser that carries the recovery-mode cookie (typically the browser that entered recovery mode) to exit. Cannot be POSTed programmatically.', 'acrossai-abilities-manager' ),
		);
	}
}
