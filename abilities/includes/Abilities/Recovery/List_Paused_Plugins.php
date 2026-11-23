<?php
/**
 * Feature 059 — List paused plugins ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.17
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Enumerates every plugin WordPress has paused after a fatal error, together
 * with the captured error details (type / file / line / message).
 */
class List_Paused_Plugins extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'recovery/list-paused-plugins',
			'args' => array(
				'label'               => __( 'List Paused Plugins', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns every plugin WordPress has paused after a fatal error, with the captured error details (type, file, line, message). Returns an empty array when no plugins are paused.', 'acrossai-abilities-manager' ),
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
						'success' => array( 'type' => 'boolean' ),
						'count'   => array( 'type' => 'integer' ),
						'paused'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'plugin_file' => array( 'type' => 'string' ),
									'plugin_name' => array( 'type' => 'string' ),
									'error'       => array(
										'type'       => 'object',
										'properties' => array(
											'type'    => array( 'type' => 'integer' ),
											'file'    => array( 'type' => 'string' ),
											'line'    => array( 'type' => 'integer' ),
											'message' => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
					),
					'required'             => array( 'success', 'count', 'paused' ),
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
		if ( ! function_exists( 'wp_paused_plugins' ) ) {
			return array( 'success' => true, 'count' => 0, 'paused' => array() );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$paused      = wp_paused_plugins()->get_all();
		$out         = array();

		foreach ( $paused as $plugin_file => $error ) {
			$name = isset( $all_plugins[ $plugin_file ]['Name'] ) ? (string) $all_plugins[ $plugin_file ]['Name'] : (string) $plugin_file;
			$out[] = array(
				'plugin_file' => (string) $plugin_file,
				'plugin_name' => $name,
				'error'       => array(
					'type'    => isset( $error['type'] ) ? (int) $error['type'] : 0,
					'file'    => isset( $error['file'] ) ? (string) $error['file'] : '',
					'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
					'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
				),
			);
		}

		return array(
			'success' => true,
			'count'   => count( $out ),
			'paused'  => $out,
		);
	}
}
