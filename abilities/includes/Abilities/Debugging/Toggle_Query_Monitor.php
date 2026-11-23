<?php
/**
 * Toggle_Query_Monitor ability (Feature 129).
 *
 * Slug: debugging/toggle-query-monitor
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\QueryMonitor\Query_Monitor_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Turn Query Monitor on or off by activating or deactivating its plugin.
 *
 * The one write in the suite. It does not uninstall or delete anything — it flips
 * whether Query Monitor runs and renders, which is the reversible "off switch" a
 * profiling tool needs. Requires the plugin to be installed; refuses with a clear
 * message when it is not.
 */
class Toggle_Query_Monitor extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'debugging/toggle-query-monitor',
			'args' => array(
				'label'               => __( 'Toggle Query Monitor', 'acrossai-abilities-manager' ),
				'description'         => __( 'Turns Query Monitor on or off by activating or deactivating its plugin. Pass enabled:true to activate, enabled:false to deactivate. Does not uninstall or delete anything. The Query Monitor plugin must be installed; this ability does not install it.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'activate_plugins' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'enabled' => array(
							'type'        => 'boolean',
							'description' => __( 'true to activate Query Monitor, false to deactivate it.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'enabled' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'   => array( 'type' => 'boolean' ),
						'installed' => array( 'type' => 'boolean' ),
						'active'    => array( 'type' => 'boolean' ),
						'changed'   => array( 'type' => 'boolean' ),
						'message'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'active', 'changed' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'query-monitor',
						'sub_group'       => 'query-monitor',
						'sub_group_label' => __( 'Query Monitor', 'acrossai-abilities-manager' ),
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
	 * Execute — activate or deactivate the Query Monitor plugin.
	 *
	 * @param array $input Ability input: { enabled: bool }.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$enabled = ! empty( $input['enabled'] );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = Query_Monitor_Guard::PLUGIN_FILE;
		$all_plugins = get_plugins();

		if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
			return array(
				'success'   => false,
				'installed' => false,
				'active'    => false,
				'changed'   => false,
				'message'   => __( 'Query Monitor is not installed. Install the plugin before toggling it.', 'acrossai-abilities-manager' ),
			);
		}

		$was_active = is_plugin_active( $plugin_file );

		if ( $enabled === $was_active ) {
			return array(
				'success'   => true,
				'installed' => true,
				'active'    => $was_active,
				'changed'   => false,
				'message'   => $was_active
					? __( 'Query Monitor is already active.', 'acrossai-abilities-manager' )
					: __( 'Query Monitor is already inactive.', 'acrossai-abilities-manager' ),
			);
		}

		if ( $enabled ) {
			$result = activate_plugin( $plugin_file );
			if ( is_wp_error( $result ) ) {
				return array(
					'success'   => false,
					'installed' => true,
					'active'    => false,
					'changed'   => false,
					'message'   => $result->get_error_message(),
				);
			}
		} else {
			deactivate_plugins( $plugin_file );
		}

		$now_active = is_plugin_active( $plugin_file );

		return array(
			'success'   => true,
			'installed' => true,
			'active'    => $now_active,
			'changed'   => $now_active !== $was_active,
			'message'   => $now_active
				? __( 'Query Monitor activated.', 'acrossai-abilities-manager' )
				: __( 'Query Monitor deactivated.', 'acrossai-abilities-manager' ),
		);
	}
}
