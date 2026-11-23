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
 * List_Db_Tables ability class (absorbed).
 */
class List_Db_Tables extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/list-db-tables',
			'args' => array(
				'label'               => __( 'List Database Tables', 'acrossai-abilities-manager' ),
				'description'         => __( 'Lists all tables in the database with engine, approximate row count, and storage size.', 'acrossai-abilities-manager' ),
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
						'success' => array( 'type' => 'boolean' ),
						'tables'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'             => array( 'type' => 'string' ),
									'engine'           => array( 'type' => 'string' ),
									'row_count'        => array( 'type' => 'integer' ),
									'data_size_bytes'  => array( 'type' => 'integer' ),
									'index_size_bytes' => array( 'type' => 'integer' ),
									'total_size_bytes' => array( 'type' => 'integer' ),
								),
							),
						),
					),
					'required'             => array( 'success', 'tables' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'database',
						'sub_group'       => 'schema',
						'sub_group_label' => __( 'Schema', 'acrossai-abilities-manager' ),
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
		$rows = $wpdb->get_results(
			'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
			 FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE()
			 ORDER BY TABLE_NAME',
			ARRAY_A
		);
		// phpcs:enable

		$tables = array();
		foreach ( (array) $rows as $row ) {
			$data_size  = (int) $row['DATA_LENGTH'];
			$index_size = (int) $row['INDEX_LENGTH'];
			$tables[]   = array(
				'name'             => $row['TABLE_NAME'],
				'engine'           => $row['ENGINE'] ?? '',
				'row_count'        => (int) $row['TABLE_ROWS'],
				'data_size_bytes'  => $data_size,
				'index_size_bytes' => $index_size,
				'total_size_bytes' => $data_size + $index_size,
			);
		}

		return array(
			'success' => true,
			'tables'  => $tables,
		);
	}
}
