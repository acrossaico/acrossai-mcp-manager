<?php
/**
 * Feature 087 — audit storage engines on the 18 core WP tables.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Database
 * @since      0.0.32
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Database;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Database_Core_Table_Allowlist;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Report the storage engine (InnoDB / MyISAM / other), data + index bytes,
 * and existence for each of the 18 core WordPress tables. Read-only.
 * Table names are always resolved from `$wpdb`; never accepts arbitrary
 * physical identifiers.
 */
class Audit_Core_Table_Engines extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/audit-core-table-engines',
			'args' => array(
				'label'               => __( 'Audit Core Table Engines', 'acrossai-abilities-manager' ),
				'description'         => __( 'Report the storage engine (InnoDB / MyISAM / other), data + index bytes, and existence for each of the 18 core WordPress tables. Read-only. Accepts an optional list of core-table keys (posts, options, users, ...); defaults to all when omitted.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'tables' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'          => array( 'type' => 'boolean' ),
						'tables'           => array( 'type' => 'array' ),
						'invalid_tables'   => array( 'type' => 'array' ),
						'audit_count'      => array( 'type' => 'integer' ),
						'innodb_count'     => array( 'type' => 'integer' ),
						'non_innodb_count' => array( 'type' => 'integer' ),
						'missing_count'    => array( 'type' => 'integer' ),
						'results'          => array( 'type' => 'array' ),
						'message'          => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'database',
						'sub_group' => 'engine',
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
		if ( isset( $input['tables'] ) && is_array( $input['tables'] ) ) {
			list( $keys, $invalid ) = Database_Core_Table_Allowlist::partition( $input['tables'] );
		} else {
			$keys    = Database_Core_Table_Allowlist::all_keys();
			$invalid = array();
		}

		global $wpdb;
		$results          = array();
		$innodb_count     = 0;
		$non_innodb_count = 0;
		$missing_count    = 0;

		foreach ( $keys as $key ) {
			$physical = Database_Core_Table_Allowlist::resolve( $key );
			if ( '' === $physical ) {
				++$missing_count;
				$results[] = array(
					'table_key'   => $key,
					'exists'      => false,
					'engine'      => '',
					'is_innodb'   => false,
					'table_type'  => '',
					'data_bytes'  => 0,
					'index_bytes' => 0,
				);
				continue;
			}
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT ENGINE, TABLE_TYPE, DATA_LENGTH, INDEX_LENGTH
					FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$physical
				),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				++$missing_count;
				$results[] = array(
					'table_key'   => $key,
					'exists'      => false,
					'engine'      => '',
					'is_innodb'   => false,
					'table_type'  => '',
					'data_bytes'  => 0,
					'index_bytes' => 0,
				);
				continue;
			}
			$engine    = (string) ( $row['ENGINE'] ?? '' );
			$is_innodb = 0 === strcasecmp( $engine, 'InnoDB' );
			if ( $is_innodb ) {
				++$innodb_count;
			} else {
				++$non_innodb_count;
			}
			$results[] = array(
				'table_key'   => $key,
				'exists'      => true,
				'engine'      => $engine,
				'is_innodb'   => $is_innodb,
				'table_type'  => (string) ( $row['TABLE_TYPE'] ?? '' ),
				'data_bytes'  => (int) ( $row['DATA_LENGTH'] ?? 0 ),
				'index_bytes' => (int) ( $row['INDEX_LENGTH'] ?? 0 ),
			);
		}

		return array(
			'success'          => true,
			'tables'           => $keys,
			'invalid_tables'   => $invalid,
			'audit_count'      => count( $keys ),
			'innodb_count'     => $innodb_count,
			'non_innodb_count' => $non_innodb_count,
			'missing_count'    => $missing_count,
			'results'          => $results,
			/* translators: 1: InnoDB count, 2: non-InnoDB count */
			'message'          => sprintf( __( 'Audited: %1$d InnoDB, %2$d non-InnoDB.', 'acrossai-abilities-manager' ), $innodb_count, $non_innodb_count ),
		);
	}
}
