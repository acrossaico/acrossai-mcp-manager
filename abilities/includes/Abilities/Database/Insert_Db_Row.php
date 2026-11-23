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
use AcrossAI_Abilities_Manager\Includes\Utilities\Database_Format_Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Insert_Db_Row ability class (absorbed).
 */
class Insert_Db_Row extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/insert-db-row',
			'args' => array(
				'label'               => __( 'Insert Row', 'acrossai-abilities-manager' ),
				'description'         => __( 'Inserts a single row into a database table using $wpdb->insert() (values are auto-escaped). Not idempotent — each call adds a new row.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'table'  => array(
							'type'        => 'string',
							'description' => __( 'Target table name (must exist in the database).', 'acrossai-abilities-manager' ),
						),
						'data'   => array(
							'type'        => 'object',
							'description' => __( 'Column → value map for the new row.', 'acrossai-abilities-manager' ),
						),
						'values' => array(
							'type'        => 'object',
							'description' => __( 'Alias for "data". If both are provided, "data" wins.', 'acrossai-abilities-manager' ),
						),
						'format' => Database_Format_Validator::schema(
							__( 'Optional format per column. Arrays follow data key order; object keys name columns and omitted columns use the default string format. Numeric formats require compatible values.', 'acrossai-abilities-manager' )
						),
					),
					'allOf'                => array(
						array( 'required' => array( 'table' ) ),
						array(
							'anyOf' => array(
								array( 'required' => array( 'data' ) ),
								array( 'required' => array( 'values' ) ),
							),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'insert_id'     => array( 'type' => 'integer' ),
						'rows_affected' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'insert_id', 'rows_affected' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'database',
						'sub_group'       => 'queries',
						'sub_group_label' => __( 'Queries', 'acrossai-abilities-manager' ),
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
		global $wpdb;

		$table  = sanitize_text_field( $input['table'] ?? '' );
		$data   = ! empty( $input['data'] ) ? $input['data'] : ( $input['values'] ?? array() );
		$format = $input['format'] ?? null;

		if ( '' === $table ) {
			return array(
				'success'       => false,
				'insert_id'     => 0,
				'rows_affected' => 0,
				'message'       => __( 'table is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return array(
				'success'       => false,
				'insert_id'     => 0,
				'rows_affected' => 0,
				'message'       => __( 'data (or its alias "values") must be a non-empty object.', 'acrossai-abilities-manager' ),
			);
		}

		$unsafe_column = Database_Format_Validator::unsafe_column( $data );
		if ( null !== $unsafe_column ) {
			return array(
				'success'       => false,
				'insert_id'     => 0,
				'rows_affected' => 0,
				'message'       => sprintf(
					/* translators: %s: the rejected column name. */
					__( 'Column name "%s" is not allowed: column names may contain only letters, numbers and underscores. This prevents a crafted name from altering the SQL $wpdb builds.', 'acrossai-abilities-manager' ),
					$unsafe_column
				),
			);
		}

		$format = Database_Format_Validator::normalize( $data, $format );
		if ( false === $format ) {
			return array(
				'success'       => false,
				'insert_id'     => 0,
				'rows_affected' => 0,
				'message'       => __( 'format keys must name data columns and numeric placeholders must match numeric values.', 'acrossai-abilities-manager' ),
			);
		}

		// Validate table exists.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		// phpcs:enable
		if ( ! in_array( $table, (array) $tables, true ) ) {
			return array(
				'success'       => false,
				'insert_id'     => 0,
				'rows_affected' => 0,
				'message'       => __( 'Table not found in the database.', 'acrossai-abilities-manager' ),
			);
		}

		$result = $wpdb->insert( $table, $data, $format );

		if ( false === $result ) {
			return array(
				'success'       => false,
				'insert_id'     => 0,
				'rows_affected' => 0,
				'message'       => $wpdb->last_error ?: __( 'Insert failed.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success'       => true,
			'insert_id'     => (int) $wpdb->insert_id,
			'rows_affected' => (int) $result,
		);
	}
}
