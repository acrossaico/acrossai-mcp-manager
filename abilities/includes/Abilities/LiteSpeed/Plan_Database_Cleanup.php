<?php
/**
 * Feature 104 — Plan Database Cleanup.
 *
 * The bridge between "LiteSpeed counts this" and "something can actually delete it". LiteSpeed's own
 * cleanup is unreachable from an ability — `DB_Optm::handler()` reads `$_GET` and ends in
 * `Admin::redirect()`, `handler_clean_db_cli()` needs `WP_CLI`, `_db_clean()` is private, and the
 * class fires no hooks — but the work is still doable through abilities this plugin already ships.
 * This ability says which, per type, with the live count so the caller can judge whether it is worth
 * doing at all.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Database_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/plan-database-cleanup — Plan Database Cleanup.
 */
final class Plan_Database_Cleanup extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'plan-database-cleanup';
	}

	protected function ability_label(): string {
		return __( 'Plan Database Cleanup', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'For each database cleanup LiteSpeed tracks, report how many rows it would affect and which ability can actually perform it. LiteSpeed\'s own cleanup cannot be run from an ability, but the work is still available through abilities this plugin already ships: cache/delete-expired-transients and cache/flush-transients for transients, comments/list-comments plus comments/delete-comment for spam, trashed comments and trackbacks, and database/delete-db-rows for revisions, trashed posts, auto-drafts and orphaned post meta. Each row carries a safe flag: safe rows go through WordPress APIs, unsafe rows are raw row deletes that leave post meta behind and need a second pass against the postmeta table. Read-only — it plans, it does not delete.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-database';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/get-database-summary',
			'cache/delete-expired-transients',
			'database/delete-db-rows',
		);
	}

	protected function input_properties(): array {
		return array(
			'only_actionable' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Return only the types that currently have rows to clean.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'recommendations' => array( 'type' => 'array' ),
			'total'           => array( 'type' => 'integer' ),
			'actionable'      => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$only_actionable = ! empty( $input['only_actionable'] );
		$advice          = Database_Repository::recommendations();

		// A list of rows, not a map keyed by type: an associative array encodes as a JSON object and
		// would fail this ability's own `array` output schema
		// (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT).
		$rows       = array();
		$total      = 0;
		$actionable = 0;

		foreach ( Database_Repository::counts() as $row ) {
			$type   = (string) $row['type'];
			$count  = (int) $row['count'];
			$total += $count;

			if ( $count > 0 ) {
				++$actionable;
			}

			if ( $only_actionable && 0 === $count ) {
				continue;
			}

			$rows[] = array(
				'type'      => $type,
				'count'     => $count,
				'describes' => (string) $row['describes'],
				'ability'   => isset( $advice[ $type ]['ability'] ) ? (string) $advice[ $type ]['ability'] : '',
				'safe'      => isset( $advice[ $type ]['safe'] ) && (bool) $advice[ $type ]['safe'],
				'how'       => isset( $advice[ $type ]['how'] ) ? (string) $advice[ $type ]['how'] : '',
			);
		}

		return array(
			'recommendations' => $rows,
			'total'           => $total,
			'actionable'      => $actionable,
			'message'         => 0 === $total
				? __( 'Nothing to clean up.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: 1: total rows, 2: number of cleanup types with rows */
					__( '%1$d row(s) across %2$d cleanup type(s). Each row names the ability that can remove them.', 'acrossai-abilities-manager' ),
					$total,
					$actionable
				),
		);
	}
}
