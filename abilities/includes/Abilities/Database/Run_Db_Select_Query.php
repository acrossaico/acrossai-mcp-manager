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

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Db_Read_Only_Session;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Db_Read_Only_Sql;

defined( 'ABSPATH' ) || exit;

/**
 * Run_Db_Select_Query ability class (absorbed).
 */
class Run_Db_Select_Query extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/run-db-select-query',
			'args' => array(
				'label'               => __( 'Run SELECT Query', 'acrossai-abilities-manager' ),
				'description'         => __( 'Executes a read-only SQL query (SELECT, SHOW, DESCRIBE, EXPLAIN). Pass the query in "sql" (or the alias "query"). Write statements are rejected. Results are capped by the limit parameter.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'sql'   => array(
							'type'        => 'string',
							'description' => __( 'SQL query to execute. Must start with SELECT, SHOW, DESCRIBE, DESC, or EXPLAIN.', 'acrossai-abilities-manager' ),
						),
						'query' => array(
							'type'        => 'string',
							'description' => __( 'Alias for "sql". If both are provided, "sql" wins.', 'acrossai-abilities-manager' ),
						),
						'limit' => array(
							'type'        => 'integer',
							'default'     => 1000,
							'minimum'     => 1,
							'maximum'     => 10000,
							'description' => __( 'Maximum rows to return (1–10000, default 1000). Appended as LIMIT if the query does not already contain one.', 'acrossai-abilities-manager' ),
						),
					),
					'anyOf'                => array(
						array( 'required' => array( 'sql' ) ),
						array( 'required' => array( 'query' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'   => array( 'type' => 'boolean' ),
						'rows'      => array( 'type' => 'array' ),
						'row_count' => array( 'type' => 'integer' ),
						'truncated' => array( 'type' => 'boolean' ),
						'message'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'rows', 'row_count', 'truncated' ),
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

		$sql   = isset( $input['sql'] ) && '' !== trim( $input['sql'] )
			? trim( $input['sql'] )
			: trim( (string) ( $input['query'] ?? '' ) );
		$limit = isset( $input['limit'] ) ? min( (int) $input['limit'], 10000 ) : 1000;

		if ( '' === $sql ) {
			return array(
				'success'   => false,
				'rows'      => array(),
				'row_count' => 0,
				'truncated' => false,
				'message'   => __( 'sql (or its alias "query") is required.', 'acrossai-abilities-manager' ),
			);
		}

		// Verb guard — the cheap first filter, kept for its legible message. Issue #293: it is not
		// the boundary. A statement beginning with SELECT can still write (INTO OUTFILE), so the
		// refusal below and the read-only transaction further down are what actually hold.
		if ( ! Db_Read_Only_Sql::verb_allowed( $sql ) ) {
			return array(
				'success'   => false,
				'rows'      => array(),
				'row_count' => 0,
				'truncated' => false,
				'message'   => sprintf(
					/* translators: %s: comma-separated list of allowed SQL verbs */
					__( 'Only %s queries are permitted.', 'acrossai-abilities-manager' ),
					implode( ', ', Db_Read_Only_Sql::ALLOWED_VERBS )
				),
			);
		}

		// Issue #293: SELECT … INTO OUTFILE / DUMPFILE passes the verb guard and writes a file
		// through MySQL, never passing the file manager's write allowlist. Refused explicitly so the
		// refusal is ours and legible even on a host where secure_file_priv would also block it.
		$file_clause = Db_Read_Only_Sql::file_write_clause( $sql );

		if ( null !== $file_clause ) {
			return array(
				'success'   => false,
				'rows'      => array(),
				'row_count' => 0,
				'truncated' => false,
				'message'   => sprintf(
					/* translators: %s: the SQL clause, e.g. OUTFILE */
					__( 'Refused: INTO %s writes a file through the database, which bypasses the file manager\'s write allowlist entirely.', 'acrossai-abilities-manager' ),
					$file_clause
				),
			);
		}

		// Appending LIMIT is an optimisation, not the cap: the cap is enforced by slicing what came
		// back. The old check matched a LIMIT anywhere in the statement — including in a subquery or
		// a string literal — and silently skipped capping when it did.
		$sql = Db_Read_Only_Sql::with_limit( $sql, $limit );

		// Issue #293: run inside a read-only transaction so MySQL refuses any write however the
		// statement is spelled, rather than leaving correctness to our parsing. Degrades to a plain
		// query on a server that will not start one — it is defence in depth behind the explicit
		// refusals above, not a replacement for them.
		$read_only = Db_Read_Only_Session::begin();

		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			// phpcs:enable
		} finally {
			$read_only();
		}

		if ( null === $rows ) {
			return array(
				'success'   => false,
				'rows'      => array(),
				'row_count' => 0,
				'truncated' => false,
				'message'   => $wpdb->last_error ?: __( 'Query returned null.', 'acrossai-abilities-manager' ),
			);
		}

		$fetched   = count( $rows );
		$truncated = false;

		// The cap, enforced on the result rather than on the SQL. This is what makes it hold for a
		// statement carrying its own LIMIT, or one the rewrite declined to touch.
		if ( $limit > 0 && $fetched > $limit ) {
			$rows      = array_slice( $rows, 0, $limit );
			$truncated = true;
		}

		$row_count = count( $rows );

		return array(
			'success'   => true,
			'rows'      => $rows,
			'row_count' => $row_count,
			'truncated' => $truncated || $row_count === $limit,
		);
	}
}
