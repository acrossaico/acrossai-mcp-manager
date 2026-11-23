<?php
/**
 * Site introspection read — wp-cron.php reachability probe (Feature 063).
 *
 * Fires a single non-blocking wp_remote_get() at wp-cron.php with a tiny
 * timeout so the ability's own response is bounded even when the cron
 * endpoint legitimately takes 30+ seconds. Reports reachability plus
 * whether DISABLE_WP_CRON is defined.
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
 * Test_Wp_Cron ability class.
 */
class Test_Wp_Cron extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/test-wp-cron',
			'args' => array(
				'label'               => __( 'Test WP-Cron', 'acrossai-abilities-manager' ),
				'description'         => __( 'Probe the site\'s wp-cron.php endpoint via a non-blocking HTTP request and report reachability plus whether DISABLE_WP_CRON is defined.', 'acrossai-abilities-manager' ),
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
						'success'         => array( 'type' => 'boolean' ),
						'reachable'       => array( 'type' => 'boolean' ),
						'disable_wp_cron' => array( 'type' => 'boolean' ),
						'message'         => array( 'type' => 'string' ),
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
		$response = wp_remote_get(
			site_url( 'wp-cron.php?doing_wp_cron' ),
			array(
				'blocking' => false,
				'timeout'  => 0.01,
			)
		);

		$reachable       = ! is_wp_error( $response );
		$disable_wp_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return array(
			'success'         => true,
			'reachable'       => $reachable,
			'disable_wp_cron' => (bool) $disable_wp_cron,
			'message'         => $reachable
				? __( 'wp-cron.php probe initiated successfully.', 'acrossai-abilities-manager' )
				: __( 'wp-cron.php probe failed to reach the endpoint.', 'acrossai-abilities-manager' ),
		);
	}
}
