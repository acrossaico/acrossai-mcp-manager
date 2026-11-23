<?php
/**
 * Ability class for database/search-replace (Feature 062).
 *
 * Walks every applicable table in the WordPress database and replaces
 * every occurrence of a source string with a target string, handling
 * PHP-serialized values via maybe_unserialize/maybe_serialize and a
 * recursive walk over string leaves. Defaults to dry-run mode — the
 * caller must explicitly pass dry_run=false to mutate any row.
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
 * Site-wide, serialized-safe database search-replace ability.
 *
 * Contract §8 in specs/062-role-caps-and-search-replace/contracts/abilities.md.
 */
class Search_Replace extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/search-replace',
			'args' => array(
				'label'               => __( 'Search Replace', 'acrossai-abilities-manager' ),
				'description'         => __( 'Replace every occurrence of a source string with a target string across every applicable table in the WordPress database, handling PHP-serialized values safely. Defaults to dry-run mode — pass dry_run=false to execute the actual replacement. Skips wp_posts.guid unless include_guids=true.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'old'           => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Source string to replace. Must be non-empty.', 'acrossai-abilities-manager' ),
						),
						'new'           => array(
							'type'        => 'string',
							'description' => __( 'Target string. May be empty to strip the source string.', 'acrossai-abilities-manager' ),
						),
						'tables'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Optional explicit list of table names. When omitted, every table starting with the WordPress prefix is scanned (or every table when all_tables=true).', 'acrossai-abilities-manager' ),
						),
						'skip_tables'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Optional list of table names to exclude from the scan.', 'acrossai-abilities-manager' ),
						),
						'skip_columns'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Optional list of column names to exclude across every table.', 'acrossai-abilities-manager' ),
						),
						'include_guids' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to rewrite wp_posts.guid values (WordPress core warns against rewriting guids).', 'acrossai-abilities-manager' ),
						),
						'all_tables'    => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true and "tables" is omitted, include every database table regardless of prefix.', 'acrossai-abilities-manager' ),
						),
						'dry_run'       => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'When true (default), no rows are mutated — only matches are counted.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'old', 'new' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'dry_run'        => array( 'type' => 'boolean' ),
						'results'        => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'table'    => array( 'type' => 'string' ),
									'column'   => array( 'type' => 'string' ),
									'matches'  => array( 'type' => 'integer' ),
									'replaced' => array( 'type' => 'integer' ),
								),
								'required'             => array( 'table', 'column', 'matches', 'replaced' ),
								'additionalProperties' => false,
							),
						),
						'summary'        => array(
							'type'                 => 'object',
							'properties'           => array(
								'tables_scanned' => array( 'type' => 'integer' ),
								'rows_matched'   => array( 'type' => 'integer' ),
								'rows_replaced'  => array( 'type' => 'integer' ),
							),
							'required'             => array( 'tables_scanned', 'rows_matched', 'rows_replaced' ),
							'additionalProperties' => false,
						),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
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
						'destructive' => true,
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

		$old           = sanitize_text_field( (string) ( $input['old'] ?? '' ) );
		$new_value     = (string) ( $input['new'] ?? '' );
		$tables_input  = isset( $input['tables'] ) && is_array( $input['tables'] ) ? array_values( array_map( 'strval', $input['tables'] ) ) : array();
		$skip_tables   = isset( $input['skip_tables'] ) && is_array( $input['skip_tables'] ) ? array_values( array_map( 'strval', $input['skip_tables'] ) ) : array();
		$skip_columns  = isset( $input['skip_columns'] ) && is_array( $input['skip_columns'] ) ? array_values( array_map( 'strval', $input['skip_columns'] ) ) : array();
		$include_guids = ! empty( $input['include_guids'] );
		$all_tables    = ! empty( $input['all_tables'] );
		$dry_run       = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;

		if ( '' === $old ) {
			return array(
				'success'        => false,
				'dry_run'        => $dry_run,
				'blocked_reason' => 'empty_old',
				'message'        => __( 'old must be a non-empty string.', 'acrossai-abilities-manager' ),
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables_available = (array) $wpdb->get_col( 'SHOW TABLES' );
		// phpcs:enable

		if ( ! empty( $tables_input ) ) {
			foreach ( $tables_input as $requested ) {
				if ( ! in_array( $requested, $tables_available, true ) ) {
					return array(
						'success'        => false,
						'dry_run'        => $dry_run,
						'blocked_reason' => 'unknown_table',
						/* translators: %s: table name */
						'message'        => sprintf( __( 'Table "%s" does not exist in the database.', 'acrossai-abilities-manager' ), $requested ),
					);
				}
			}
			$target_tables = $tables_input;
		} elseif ( $all_tables ) {
			$target_tables = $tables_available;
		} else {
			$prefix        = (string) $wpdb->prefix;
			$target_tables = array_values(
				array_filter(
					$tables_available,
					static function ( $table_name ) use ( $prefix ): bool {
						return '' !== $prefix && str_starts_with( (string) $table_name, $prefix );
					}
				)
			);
		}

		if ( ! empty( $skip_tables ) ) {
			$target_tables = array_values( array_diff( $target_tables, $skip_tables ) );
		}

		$results        = array();
		$total_matches  = 0;
		$total_replaced = 0;

		foreach ( $target_tables as $table ) {
			$table = (string) $table;
			if ( false !== strpos( $table, '`' ) ) {
				continue;
			}

			$escaped = '`' . esc_sql( $table ) . '`';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$columns_meta = $wpdb->get_results( "DESCRIBE {$escaped}", ARRAY_A );
			// phpcs:enable

			$primary_key = $this->pick_primary_key( (array) $columns_meta );
			if ( null === $primary_key ) {
				continue;
			}

			$string_columns = $this->filter_string_columns( (array) $columns_meta );

			foreach ( $string_columns as $column ) {
				if ( in_array( $column, $skip_columns, true ) ) {
					continue;
				}
				if ( ! $include_guids && $wpdb->posts === $table && 'guid' === $column ) {
					continue;
				}

				$escaped_pk  = '`' . esc_sql( $primary_key ) . '`';
				$escaped_col = '`' . esc_sql( $column ) . '`';

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results(
					"SELECT {$escaped_pk} AS pk, {$escaped_col} AS value FROM {$escaped}",
					ARRAY_A
				);
				// phpcs:enable

				$matches  = 0;
				$replaced = 0;

				foreach ( (array) $rows as $row ) {
					if ( ! is_array( $row ) || ! array_key_exists( 'value', $row ) ) {
						continue;
					}
					$raw = $row['value'];
					if ( null === $raw ) {
						continue;
					}
					$raw_str = (string) $raw;
					if ( false === strpos( $raw_str, $old ) ) {
						continue;
					}

					$new_row_value = $this->replace_in_value( $raw_str, $old, $new_value );

					if ( $new_row_value === $raw_str ) {
						continue;
					}

					++$matches;

					if ( $dry_run ) {
						continue;
					}

					$update = $wpdb->update(
						$table,
						array( $column => $new_row_value ),
						array( $primary_key => $row['pk'] )
					);
					if ( false !== $update ) {
						++$replaced;
					}
				}

				$results[] = array(
					'table'    => $table,
					'column'   => $column,
					'matches'  => $matches,
					'replaced' => $replaced,
				);

				$total_matches  += $matches;
				$total_replaced += $replaced;
			}
		}

		return array(
			'success' => true,
			'dry_run' => $dry_run,
			'results' => $results,
			'summary' => array(
				'tables_scanned' => count( $target_tables ),
				'rows_matched'   => $total_matches,
				'rows_replaced'  => $total_replaced,
			),
			'message' => $dry_run
				? sprintf(
					/* translators: 1: match count, 2: tables scanned */
					__( 'Dry-run: %1$d row match(es) would be updated across %2$d table(s).', 'acrossai-abilities-manager' ),
					$total_matches,
					count( $target_tables )
				)
				: sprintf(
					/* translators: 1: replaced count, 2: match count, 3: tables scanned */
					__( 'Search-replace applied: %1$d/%2$d row(s) updated across %3$d table(s).', 'acrossai-abilities-manager' ),
					$total_replaced,
					$total_matches,
					count( $target_tables )
				),
		);
	}

	/**
	 * Pick the primary-key column name from a DESCRIBE result set.
	 *
	 * Falls back to the first PRI column, or null if none exists (in which
	 * case the table is skipped — we cannot safely update rows without a
	 * unique identifier).
	 *
	 * @param array $columns_meta DESCRIBE rows in ARRAY_A form.
	 * @return string|null
	 */
	private function pick_primary_key( array $columns_meta ): ?string {
		foreach ( $columns_meta as $meta ) {
			if ( is_array( $meta ) && isset( $meta['Key'], $meta['Field'] ) && 'PRI' === $meta['Key'] ) {
				return (string) $meta['Field'];
			}
		}
		return null;
	}

	/**
	 * Return the subset of column names whose SQL type can hold a string
	 * value we might need to rewrite (char/varchar/text/blob variants).
	 *
	 * @param array $columns_meta DESCRIBE rows in ARRAY_A form.
	 * @return string[]
	 */
	private function filter_string_columns( array $columns_meta ): array {
		$string_types = array( 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob' );
		$columns      = array();
		foreach ( $columns_meta as $meta ) {
			if ( ! is_array( $meta ) || ! isset( $meta['Type'], $meta['Field'] ) ) {
				continue;
			}
			$type = strtolower( (string) $meta['Type'] );
			foreach ( $string_types as $string_type ) {
				if ( 0 === strpos( $type, $string_type ) ) {
					$columns[] = (string) $meta['Field'];
					break;
				}
			}
		}
		return $columns;
	}

	/**
	 * Serialized-safe search-replace on a single scalar value.
	 *
	 * If maybe_unserialize returns a value distinct from the raw input,
	 * we walk it recursively and rewrite every string leaf, then
	 * re-serialize. Otherwise we fall back to a plain str_replace.
	 *
	 * @param string $raw Raw column value from the database.
	 * @param string $old Source substring.
	 * @param string $new_value Target substring.
	 * @return string
	 */
	private function replace_in_value( string $raw, string $old, string $new_value ): string {
		$maybe = maybe_unserialize( $raw );
		if ( is_array( $maybe ) || is_object( $maybe ) ) {
			$walked = $this->walk_and_replace( $maybe, $old, $new_value );
			$out    = maybe_serialize( $walked );
			return is_string( $out ) ? $out : $raw;
		}
		return str_replace( $old, $new_value, $raw );
	}

	/**
	 * Recursively walk arrays / objects and rewrite every string leaf.
	 *
	 * @param mixed  $data      Value to walk.
	 * @param string $old       Source substring.
	 * @param string $new_value Target substring.
	 * @return mixed
	 */
	private function walk_and_replace( $data, string $old, string $new_value ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$out[ $key ] = $this->walk_and_replace( $value, $old, $new_value );
			}
			return $out;
		}

		if ( is_object( $data ) ) {
			$clone = clone $data;
			foreach ( get_object_vars( $clone ) as $prop => $value ) {
				$clone->{$prop} = $this->walk_and_replace( $value, $old, $new_value );
			}
			return $clone;
		}

		if ( is_string( $data ) ) {
			return str_replace( $old, $new_value, $data );
		}

		return $data;
	}
}
