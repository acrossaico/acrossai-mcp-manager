<?php
/**
 * Feature 086 — index health diagnostics for the current site's tables.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Database
 * @since      0.0.32
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Database;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded, paginated snapshot of index shape and storage for the current
 * site's tables. Reads information_schema.TABLES + STATISTICS. Never
 * accepts an arbitrary physical table or index name from the caller.
 */
class Audit_Index_Health extends Ability_Definition {

	private const DEFAULT_LIMIT = 25;
	private const MAX_LIMIT     = 100;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/audit-index-health',
			'args' => array(
				'label'               => __( 'Audit Index Health', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return a bounded, paginated snapshot of table + index metadata for the current site: engine, row estimate, data/index/free bytes, and index shape (name, unique, columns). Filters to the current-blog prefix and rejects sibling-site tables on multisite.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_LIMIT,
							'default' => self::DEFAULT_LIMIT,
						),
						'offset' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'             => array( 'type' => 'boolean' ),
						'observed_at'         => array( 'type' => 'string' ),
						'table_prefix'        => array( 'type' => 'string' ),
						'total_table_count'   => array( 'type' => 'integer' ),
						'returned_table_count' => array( 'type' => 'integer' ),
						'limit'               => array( 'type' => 'integer' ),
						'offset'              => array( 'type' => 'integer' ),
						'next_offset'         => array( 'type' => 'integer' ),
						'total_data_bytes'    => array( 'type' => 'integer' ),
						'total_index_bytes'   => array( 'type' => 'integer' ),
						'total_free_bytes'    => array( 'type' => 'integer' ),
						'engine_counts'       => array( 'type' => 'object' ),
						'issue_count'         => array( 'type' => 'integer' ),
						'issues'              => array( 'type' => 'array' ),
						'tables'              => array( 'type' => 'array' ),
						'message'             => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'database',
						'sub_group' => 'audit',
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$limit  = max( 1, min( self::MAX_LIMIT, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		global $wpdb;
		$prefix     = (string) $wpdb->prefix;
		$base       = (string) $wpdb->base_prefix;
		$is_ms      = function_exists( 'is_multisite' ) && is_multisite();

		$like = str_replace( array( '\\', '_', '%' ), array( '\\\\', '\_', '\%' ), $prefix ) . '%';

		$all_tables = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, TABLE_COLLATION, ROW_FORMAT
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s
				ORDER BY TABLE_NAME ASC',
				DB_NAME,
				$like
			),
			ARRAY_A
		);

		if ( ! is_array( $all_tables ) ) {
			$all_tables = array();
		}

		// On multisite the LIKE `wp_%` catches sibling blog tables (wp_2_*).
		// Filter to only current-site tables: those starting with $prefix but
		// NOT containing a numeric second segment when $prefix === $base.
		$scoped = array();
		foreach ( $all_tables as $t ) {
			$name = (string) ( $t['TABLE_NAME'] ?? '' );
			if ( '' === $name || 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			if ( $is_ms && $prefix === $base ) {
				$rest = substr( $name, strlen( $prefix ) );
				if ( '' !== $rest && preg_match( '/^\d+_/', $rest ) ) {
					continue;
				}
			}
			$scoped[] = $t;
		}

		$total = count( $scoped );
		$slice = array_slice( $scoped, $offset, $limit );

		$total_data  = 0;
		$total_index = 0;
		$total_free  = 0;
		$engine_counts = array();
		$issues = array();
		$tables_out = array();

		foreach ( $slice as $row ) {
			$name   = (string) ( $row['TABLE_NAME'] ?? '' );
			$engine = (string) ( $row['ENGINE'] ?? '' );
			$data   = (int) ( $row['DATA_LENGTH'] ?? 0 );
			$idx    = (int) ( $row['INDEX_LENGTH'] ?? 0 );
			$free   = (int) ( $row['DATA_FREE'] ?? 0 );

			$total_data  += $data;
			$total_index += $idx;
			$total_free  += $free;
			$engine_counts[ $engine ] = ( $engine_counts[ $engine ] ?? 0 ) + 1;

			$idx_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, CARDINALITY
					FROM information_schema.STATISTICS
					WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
					ORDER BY INDEX_NAME ASC, SEQ_IN_INDEX ASC',
					DB_NAME,
					$name
				),
				ARRAY_A
			);
			$indexes    = array();
			$has_primary = false;
			if ( is_array( $idx_rows ) ) {
				foreach ( $idx_rows as $ir ) {
					$in = (string) ( $ir['INDEX_NAME'] ?? '' );
					if ( 'PRIMARY' === $in ) {
						$has_primary = true;
					}
					if ( ! isset( $indexes[ $in ] ) ) {
						$indexes[ $in ] = array(
							'index_name' => $in,
							'unique'     => 0 === (int) ( $ir['NON_UNIQUE'] ?? 1 ),
							'columns'    => array(),
							'cardinality' => 0,
						);
					}
					$indexes[ $in ]['columns'][] = (string) ( $ir['COLUMN_NAME'] ?? '' );
					$indexes[ $in ]['cardinality'] = max( (int) $indexes[ $in ]['cardinality'], (int) ( $ir['CARDINALITY'] ?? 0 ) );
				}
			}

			if ( ! $has_primary ) {
				$issues[] = array(
					'code'     => 'missing_primary_key',
					'severity' => 'warning',
					'table_name' => $name,
					'index_name' => '',
					'message'  => sprintf( 'Table %s has no PRIMARY KEY.', $name ),
				);
			}

			$tables_out[] = array(
				'table_name'    => $name,
				'engine'        => $engine,
				'rows_estimate' => (int) ( $row['TABLE_ROWS'] ?? 0 ),
				'data_bytes'    => $data,
				'index_bytes'   => $idx,
				'free_bytes'    => $free,
				'row_format'    => (string) ( $row['ROW_FORMAT'] ?? '' ),
				'collation'     => (string) ( $row['TABLE_COLLATION'] ?? '' ),
				'has_primary_key' => $has_primary,
				'index_count'   => count( $indexes ),
				'indexes'       => array_values( $indexes ),
			);
		}

		$next_offset = $offset + count( $slice );
		if ( $next_offset >= $total ) {
			$next_offset = $total;
		}

		return array(
			'success'             => true,
			'observed_at'         => gmdate( 'c' ),
			'table_prefix'        => $prefix,
			'total_table_count'   => $total,
			'returned_table_count' => count( $slice ),
			'limit'               => $limit,
			'offset'              => $offset,
			'next_offset'         => $next_offset,
			'total_data_bytes'    => $total_data,
			'total_index_bytes'   => $total_index,
			'total_free_bytes'    => $total_free,
			'engine_counts'       => (object) $engine_counts,
			'issue_count'         => count( $issues ),
			'issues'              => $issues,
			'tables'              => $tables_out,
			/* translators: 1: returned table count, 2: total table count */
			'message'             => sprintf( __( 'Returned %1$d of %2$d tables in the current-site scope.', 'acrossai-abilities-manager' ), count( $slice ), $total ),
		);
	}
}
