<?php
/**
 * Feature 104 — Get Database Cleanup Summary.
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
 * litespeed/get-database-summary — Get Database Cleanup Summary.
 */
final class Get_Database_Summary extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-database-summary';
	}

	protected function ability_label(): string {
		return __( 'Get Database Cleanup Summary', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'How many rows each cleanup type would affect: post revisions, orphaned post meta, auto-drafts, trashed posts, spam and trashed comments, trackbacks, and transients. Read-only — it reports what a cleanup would remove without removing anything. LiteSpeed\'s own cleanup cannot be run from an ability, but the work is still available through abilities this plugin ships: call litespeed/plan-database-cleanup, which returns the same counts alongside the exact ability to use for each type.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-database';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/plan-database-cleanup',
		);
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'types' => array( 'type' => 'array' ),

			'total' => array( 'type' => 'integer' ),
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
		$rows  = Database_Repository::counts();
		$total = 0;

		foreach ( $rows as $row ) {
			$total += (int) $row['count'];
		}

		return array(
			'types'   => $rows,
			'total'   => $total,
			'message' => sprintf(
				/* translators: %d: total rows */
				__( '%d row(s) could be cleaned up.', 'acrossai-abilities-manager' ),
				$total
			),
		);
	}
}
