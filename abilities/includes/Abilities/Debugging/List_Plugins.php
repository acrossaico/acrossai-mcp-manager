<?php
/**
 * List_Plugins ability (Feature 061).
 *
 * Slug: acrossai/conflict-test-list-plugins
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List every installed plugin with the fields needed to drive conflict testing.
 *
 * Returns DB-recorded active state (from get_option('active_plugins')), not the
 * mu-plugin-filtered effective state — this ability describes the underlying
 * reality, not the override view.
 */
class List_Plugins extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'acrossai/conflict-test-list-plugins',
			'args' => array(
				'label'               => __( 'List Plugins (Conflict Testing)', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns every installed plugin with its file identifier, name, version, DB-recorded active state, and any plugins it declares as required (WordPress 6.5+ Requires Plugins header).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugins' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'plugin_file'      => array( 'type' => 'string' ),
									'name'             => array( 'type' => 'string' ),
									'version'          => array( 'type' => 'string' ),
									'status'           => array( 'type' => 'string', 'enum' => array( 'active', 'inactive' ) ),
									'requires_plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
								),
								'required'             => array( 'plugin_file', 'name', 'version', 'status', 'requires_plugins' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'plugins' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'conflict-testing',
						'sub_group_label' => __( 'Conflict Testing', 'acrossai-abilities-manager' ),
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
	 * Execute — enumerate installed plugins.
	 *
	 * @param array $input Ignored — this ability accepts no input.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}

		$plugins = array();
		foreach ( $all_plugins as $plugin_file => $data ) {
			$requires_header = isset( $data['RequiresPlugins'] ) ? (string) $data['RequiresPlugins'] : '';
			$requires        = array_values(
				array_filter( array_map( 'trim', explode( ',', $requires_header ) ) )
			);

			$plugins[] = array(
				'plugin_file'      => (string) $plugin_file,
				'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'status'           => in_array( $plugin_file, $active_plugins, true ) ? 'active' : 'inactive',
				'requires_plugins' => $requires,
			);
		}

		return array( 'plugins' => $plugins );
	}
}
