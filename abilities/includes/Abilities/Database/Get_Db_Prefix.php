<?php
/**
 * Site introspection read — database table prefix (Feature 063).
 *
 * Returns the current-blog table prefix and the multisite base prefix
 * as reported by the global $wpdb instance.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Database
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Database;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Get_Db_Prefix ability class.
 */
class Get_Db_Prefix extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/get-db-prefix',
			'args' => array(
				'label'               => __( 'Get Database Prefix', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the current-blog database table prefix and the multisite base (network) prefix.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
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
						'success'     => array( 'type' => 'boolean' ),
						'prefix'      => array( 'type' => 'string' ),
						'base_prefix' => array( 'type' => 'string' ),
						'message'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'database',
						'sub_group'       => 'introspection',
						'sub_group_label' => __( 'Introspection', 'acrossai-abilities-manager' ),
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
		$wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;

		$prefix      = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$base_prefix = is_object( $wpdb ) && isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : $prefix;

		return array(
			'success'     => true,
			'prefix'      => $prefix,
			'base_prefix' => $base_prefix,
			'message'     => __( 'Database prefix fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
