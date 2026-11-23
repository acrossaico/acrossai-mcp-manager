<?php
/**
 * Feature 087 — convert specified core WP tables to InnoDB via ALTER TABLE.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Database
 * @since      0.0.32
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Database;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Database_Core_Table_Allowlist;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Database_Mutation_Attribution;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Convert specified core WordPress tables to InnoDB via `ALTER TABLE ... ENGINE = InnoDB`.
 * Table names always resolved from `$wpdb`; never accepts arbitrary identifiers.
 * Live writes require `dry_run=false` AND `confirm=true`. Postcondition verified
 * (re-read engine after) and mutation-attributed (statement outcome ≠ postcondition).
 */
class Convert_Core_Tables_To_Innodb extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/convert-core-tables-to-innodb',
			'args' => array(
				'label'               => __( 'Convert Core Tables to InnoDB', 'acrossai-abilities-manager' ),
				'description'         => __( 'Convert specified core WordPress tables to InnoDB via ALTER TABLE. Only accepts logical core-table keys (posts, options, users, ...); never arbitrary identifiers. Live writes require dry_run=false AND confirm=true. Postcondition verified via engine re-read; mutation attribution separates statement outcome from postcondition.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'tables'  => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'minItems' => 1,
						),
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'confirm' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'tables' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'                    => array( 'type' => 'boolean' ),
						'dry_run'                    => array( 'type' => 'boolean' ),
						'confirmed'                  => array( 'type' => 'boolean' ),
						'tables'                     => array( 'type' => 'array' ),
						'invalid_tables'             => array( 'type' => 'array' ),
						'planned_count'              => array( 'type' => 'integer' ),
						'changed_count'              => array( 'type' => 'integer' ),
						'unchanged_count'            => array( 'type' => 'integer' ),
						'unknown_mutation_count'     => array( 'type' => 'integer' ),
						'failed_count'               => array( 'type' => 'integer' ),
						'mutation_outcome'           => array( 'type' => 'string' ),
						'mutation_occurred'          => array( 'type' => array( 'boolean', 'null' ) ),
						'partial_mutation'           => array( 'type' => array( 'boolean', 'null' ) ),
						'results'                    => array( 'type' => 'array' ),
						'message'                    => array( 'type' => 'string' ),
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
						'readonly'    => false,
						'destructive' => true,
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
		$dry_run = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;
		$confirm = (bool) ( $input['confirm'] ?? false );

		list( $keys, $invalid ) = Database_Core_Table_Allowlist::partition( $input['tables'] ?? array() );

		if ( array() === $keys ) {
			return $this->empty_result( $dry_run, $confirm, $invalid, __( 'No valid core-table keys provided.', 'acrossai-abilities-manager' ) );
		}

		global $wpdb;

		$results  = array();
		$outcomes = array();
		$planned  = 0;
		$changed  = 0;
		$unchanged = 0;
		$unknown   = 0;
		$failed    = 0;

		foreach ( $keys as $key ) {
			$physical = Database_Core_Table_Allowlist::resolve( $key );
			if ( '' === $physical ) {
				++$failed;
				$results[] = array(
					'table_key'         => $key,
					'physical_name'     => '',
					'before_engine'     => '',
					'after_engine'      => '',
					'statement_outcome' => 'not_attempted',
					'postcondition'     => Database_Mutation_Attribution::POSTCOND_UNKNOWN,
					'mutation_outcome'  => Database_Mutation_Attribution::OUTCOME_UNKNOWN,
					'error_code'        => 'resolve_failed',
				);
				$outcomes[] = Database_Mutation_Attribution::OUTCOME_UNKNOWN;
				continue;
			}

			$before = (string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$physical
				)
			);
			$was_innodb = 0 === strcasecmp( $before, 'InnoDB' );
			++$planned;

			if ( $was_innodb ) {
				$results[] = array(
					'table_key'         => $key,
					'physical_name'     => $physical,
					'before_engine'     => $before,
					'after_engine'      => $before,
					'statement_outcome' => 'not_needed',
					'postcondition'     => Database_Mutation_Attribution::POSTCOND_MET,
					'mutation_outcome'  => Database_Mutation_Attribution::OUTCOME_UNCHANGED,
					'error_code'        => '',
				);
				$outcomes[] = Database_Mutation_Attribution::OUTCOME_UNCHANGED;
				++$unchanged;
				continue;
			}

			if ( $dry_run || ! $confirm ) {
				$results[] = array(
					'table_key'         => $key,
					'physical_name'     => $physical,
					'before_engine'     => $before,
					'after_engine'      => $before,
					'statement_outcome' => 'dry_run',
					'postcondition'     => Database_Mutation_Attribution::POSTCOND_UNKNOWN,
					'mutation_outcome'  => Database_Mutation_Attribution::OUTCOME_NONE,
					'error_code'        => '',
				);
				$outcomes[] = Database_Mutation_Attribution::OUTCOME_NONE;
				continue;
			}

			$stmt_result = $wpdb->query(
				$wpdb->prepare( 'ALTER TABLE %i ENGINE = InnoDB', $physical )
			);
			$stmt_ok = false !== $stmt_result;

			$after = (string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$physical
				)
			);
			$post_met = '' !== $after
				? ( 0 === strcasecmp( $after, 'InnoDB' ) ? Database_Mutation_Attribution::POSTCOND_MET : Database_Mutation_Attribution::POSTCOND_FAILED )
				: Database_Mutation_Attribution::POSTCOND_UNKNOWN;

			$outcome = Database_Mutation_Attribution::classify( $stmt_ok, $post_met, $was_innodb );
			$outcomes[] = $outcome;

			if ( Database_Mutation_Attribution::OUTCOME_CHANGED === $outcome ) {
				++$changed;
			} elseif ( Database_Mutation_Attribution::OUTCOME_UNCHANGED === $outcome ) {
				++$unchanged;
			} elseif ( Database_Mutation_Attribution::OUTCOME_UNKNOWN === $outcome ) {
				++$unknown;
			} else {
				++$failed;
			}

			$results[] = array(
				'table_key'         => $key,
				'physical_name'     => $physical,
				'before_engine'     => $before,
				'after_engine'      => $after,
				'statement_outcome' => $stmt_ok ? 'succeeded' : 'failed',
				'postcondition'     => $post_met,
				'mutation_outcome'  => $outcome,
				'error_code'        => $stmt_ok ? '' : (string) $wpdb->last_error,
			);
		}

		$aggregate = Database_Mutation_Attribution::aggregate( $outcomes );

		return array(
			'success'                => true,
			'dry_run'                => $dry_run,
			'confirmed'              => $confirm,
			'tables'                 => $keys,
			'invalid_tables'         => $invalid,
			'planned_count'          => $planned,
			'changed_count'          => $changed,
			'unchanged_count'        => $unchanged,
			'unknown_mutation_count' => $unknown,
			'failed_count'           => $failed,
			'mutation_outcome'       => $aggregate['outcome'],
			'mutation_occurred'      => $aggregate['mutation_occurred'],
			'partial_mutation'       => $aggregate['partial_mutation'],
			'results'                => $results,
			'message'                => ( $dry_run || ! $confirm )
				/* translators: %d: planned table count */
				? sprintf( __( 'Dry run: %d table(s) planned for conversion. Pass dry_run=false and confirm=true to execute.', 'acrossai-abilities-manager' ), $planned )
				/* translators: 1: changed, 2: unchanged, 3: unknown, 4: failed */
				: sprintf( __( 'Converted: changed %1$d, unchanged %2$d, unknown %3$d, failed %4$d.', 'acrossai-abilities-manager' ), $changed, $unchanged, $unknown, $failed ),
		);
	}

	/**
	 * Empty-input result envelope.
	 *
	 * @param bool     $dry_run  Dry-run flag.
	 * @param bool     $confirm  Confirm flag.
	 * @param string[] $invalid  Invalid keys.
	 * @param string   $message  Message.
	 * @return array<string,mixed>
	 */
	private function empty_result( bool $dry_run, bool $confirm, array $invalid, string $message ): array {
		return array(
			'success'                => false,
			'dry_run'                => $dry_run,
			'confirmed'              => $confirm,
			'tables'                 => array(),
			'invalid_tables'         => $invalid,
			'planned_count'          => 0,
			'changed_count'          => 0,
			'unchanged_count'        => 0,
			'unknown_mutation_count' => 0,
			'failed_count'           => 0,
			'mutation_outcome'       => Database_Mutation_Attribution::OUTCOME_NONE,
			'mutation_occurred'      => false,
			'partial_mutation'       => false,
			'results'                => array(),
			'message'                => $message,
		);
	}
}
