<?php
/**
 * Feature 069 — restore or delete a Rank Math settings backup.
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
 * Ability #25 — rank-math/manage-backup.
 *
 * Restore and delete share one class because both take a key and both are
 * destructive; create is separate because it is not.
 */
class Manage_Backup extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'manage-backup';
	}

	protected function ability_label(): string {
		return __( 'Restore or Delete Rank Math Backup', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Restore a Rank Math settings backup, overwriting all current settings, or delete one permanently. Both are irreversible: restoring discards the present configuration, and deleting removes the only copy. List keys with rank-math/list-backups, and consider rank-math/create-backup first so the current state is recoverable.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-status';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'action' => array(
				'type'        => 'string',
				'enum'        => array( 'restore', 'delete' ),
				'description' => __( 'restore overwrites current settings; delete removes the backup.', 'acrossai-abilities-manager' ),
			),
			'key'    => array(
				'type'        => 'string',
				'description' => __( 'Backup key from rank-math/list-backups.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'action'    => array( 'type' => 'string' ),
			'key'       => array( 'type' => 'string' ),
			'remaining' => array( 'type' => 'integer' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'action', 'key' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Status_Tools_Repository::manage_backup(
			isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '',
			isset( $input['key'] ) ? sanitize_text_field( (string) $input['key'] ) : ''
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = 'restore' === $result['action']
			? sprintf(
				/* translators: %s: backup key */
				__( 'Restored Rank Math settings from backup "%s". All previous settings were overwritten.', 'acrossai-abilities-manager' ),
				$result['key']
			)
			: sprintf(
				/* translators: %s: backup key */
				__( 'Deleted Rank Math settings backup "%s".', 'acrossai-abilities-manager' ),
				$result['key']
			);

		return $result;
	}
}
