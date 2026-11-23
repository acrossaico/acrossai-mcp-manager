<?php
/**
 * Feature 069 — create a Rank Math settings backup.
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
 * Ability #24 — rank-math/create-backup.
 *
 * Split from manage-backup because creating is non-destructive while restoring and
 * deleting are — and an ability can only carry one annotation triple honestly.
 *
 * Not idempotent: each call adds another backup.
 */
class Create_Backup extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'create-backup';
	}

	protected function ability_label(): string {
		return __( 'Create Rank Math Settings Backup', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Snapshot the current Rank Math settings so they can be restored later. Cheap and safe — take one before any bulk settings change. Each call creates an additional backup.', 'acrossai-abilities-manager' );
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
			'key'   => array( 'type' => 'string' ),
			'total' => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Status_Tools_Repository::create_backup();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = '' !== $result['key']
			? sprintf(
				/* translators: %s: backup key */
				__( 'Created Rank Math settings backup "%s".', 'acrossai-abilities-manager' ),
				$result['key']
			)
			: __( 'Created a Rank Math settings backup, but Rank Math did not report its key. List backups to find it.', 'acrossai-abilities-manager' );

		return $result;
	}
}
