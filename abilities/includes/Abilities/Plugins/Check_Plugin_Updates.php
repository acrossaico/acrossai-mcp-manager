<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Plugins
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Plugins;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Check_Plugin_Updates ability class (absorbed).
 */
class Check_Plugin_Updates extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/check-plugin-updates',
			'args' => array(
				'label'               => __( 'Check Updates', 'acrossai-abilities-manager' ),
				'description'         => __( 'Check for available WordPress core, plugin, and theme updates.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-plugins',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'core'    => array(
							'type'        => 'object',
							'description' => __( 'Core update info.', 'acrossai-abilities-manager' ),
						),
						'plugins' => array(
							'type'        => 'array',
							'description' => __( 'Plugins with available updates.', 'acrossai-abilities-manager' ),
						),
						'themes'  => array(
							'type'        => 'array',
							'description' => __( 'Themes with available updates.', 'acrossai-abilities-manager' ),
						),
						'total'   => array(
							'type'        => 'integer',
							'description' => __( 'Total number of available updates.', 'acrossai-abilities-manager' ),
						),
					),
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'updates',
						'sub_group'       => 'info',
						'sub_group_label' => __( 'Info', 'acrossai-abilities-manager' ),
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
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$core_updates = get_core_updates();
		$core_info    = array(
			'current'     => get_bloginfo( 'version' ),
			'available'   => false,
			'new_version' => '',
		);

		if ( ! empty( $core_updates ) && 'upgrade' === $core_updates[0]->response ) {
			$core_info['available']   = true;
			$core_info['new_version'] = $core_updates[0]->version;
		}

		$plugin_updates  = get_plugin_updates();
		$plugins_needing = array();

		foreach ( $plugin_updates as $file => $data ) {
			$plugins_needing[] = array(
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WordPress plugin data properties.
				'name'        => $data->Name,
				'slug'        => $file,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'current'     => $data->Version,
				'new_version' => $data->update->new_version,
			);
		}

		$theme_updates  = get_theme_updates();
		$themes_needing = array();

		foreach ( $theme_updates as $slug => $theme ) {
			$themes_needing[] = array(
				'name'        => $theme->get( 'Name' ),
				'slug'        => $slug,
				'current'     => $theme->get( 'Version' ),
				'new_version' => $theme->update['new_version'],
			);
		}

		$total = ( $core_info['available'] ? 1 : 0 ) + count( $plugins_needing ) + count( $themes_needing );

		return array(
			'core'    => $core_info,
			'plugins' => $plugins_needing,
			'themes'  => $themes_needing,
			'total'   => $total,
		);
	}
}
