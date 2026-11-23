<?php
/**
 * Feature 069 — list Rank Math settings backups.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Status_Tools_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #23 — rank-math/list-backups.
 *
 * The discovery read for rank-math/manage-backup, which needs a key.
 *
 * Read-only, idempotent.
 */
class List_Backups extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'list-backups';
	}

	protected function ability_label(): string {
		return __( 'List Rank Math Settings Backups', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'List existing Rank Math settings backups with their keys and dates. Rank Math creates one automatically before an import. Pass a key to rank-math/manage-backup to restore or delete it.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-status';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'backups' => array( 'type' => 'array' ),
			'count'   => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$backups = Status_Tools_Repository::list_backups();

		return array(
			'backups' => $backups,
			'count'   => count( $backups ),
			'message' => 0 === count( $backups )
				? __( 'No Rank Math settings backups exist. Create one with rank-math/create-backup.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of backups */
					_n( 'Found %d Rank Math settings backup.', 'Found %d Rank Math settings backups.', count( $backups ), 'acrossai-abilities-manager' ),
					count( $backups )
				),
		);
	}
}
