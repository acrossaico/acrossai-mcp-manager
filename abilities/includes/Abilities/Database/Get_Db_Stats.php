<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
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
 * Get_Db_Stats ability class (absorbed).
 */
class Get_Db_Stats extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/get-db-stats',
			'args' => array(
				'label'               => __( 'Database Stats', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns a summary of the WordPress database: version, name, table count, total size, charset, and collation.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
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
					'type'                 => 'object',
					'properties'           => array(
						'success'          => array( 'type' => 'boolean' ),
						'db_version'       => array( 'type' => 'string' ),
						'db_name'          => array( 'type' => 'string' ),
						'table_count'      => array( 'type' => 'integer' ),
						'total_size_bytes' => array( 'type' => 'integer' ),
						'charset'          => array( 'type' => 'string' ),
						'collation'        => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'db_version', 'db_name', 'table_count', 'total_size_bytes', 'charset', 'collation' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'database',
						'sub_group'       => 'maintenance',
						'sub_group_label' => __( 'Maintenance', 'acrossai-abilities-manager' ),
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
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_name = $wpdb->get_var( 'SELECT DATABASE()' );
		$stats   = $wpdb->get_row(
			'SELECT COUNT(*) AS table_count,
			        COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS total_size
			 FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE()',
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'success'          => true,
			'db_version'       => $wpdb->db_version(),
			'db_name'          => (string) $db_name,
			'table_count'      => isset( $stats['table_count'] ) ? (int) $stats['table_count'] : 0,
			'total_size_bytes' => isset( $stats['total_size'] ) ? (int) $stats['total_size'] : 0,
			'charset'          => $wpdb->charset,
			'collation'        => $wpdb->collate,
		);
	}
}
