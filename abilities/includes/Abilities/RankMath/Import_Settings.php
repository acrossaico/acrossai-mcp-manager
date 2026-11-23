<?php
/**
 * Feature 069 — import Rank Math settings.
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
 * Ability #22 — rank-math/import-settings.
 *
 * Genuinely destructive: Rank Math's do_import_data() writes each panel with
 * update_option() and NO merge, so it REPLACES whole option blobs rather than
 * merging into them. It does snapshot a backup first, and the created key is
 * returned so the caller has an undo path.
 *
 * Takes the payload as an object rather than a file upload, because Rank Math's own
 * import() reads $_FILES and is unusable from an ability.
 */
class Import_Settings extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'import-settings';
	}

	protected function ability_label(): string {
		return __( 'Import Rank Math Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Import a Rank Math settings payload produced by rank-math/export-settings. Each included panel REPLACES the current settings for that panel wholesale — it does not merge — so any setting absent from the payload reverts to its stored default. Rank Math creates a backup first and its key is returned, so the change can be undone with rank-math/manage-backup.', 'acrossai-abilities-manager' );
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
			'data' => array(
				'type'                 => 'object',
				'description'          => __( 'Payload from rank-math/export-settings. Keys are panel names.', 'acrossai-abilities-manager' ),
				'additionalProperties' => true,
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'panels_imported' => array( 'type' => 'array' ),
			'backup_key'      => array( 'type' => 'string' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'data' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['data'] ) || ! is_array( $input['data'] ) ) {
			return new WP_Error( 'invalid_input', __( 'data must be an object of settings panels.', 'acrossai-abilities-manager' ) );
		}

		$result = Status_Tools_Repository::import_settings( $input['data'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = '' !== $result['backup_key']
			? sprintf(
				/* translators: 1: comma-separated panel names, 2: backup key */
				__( 'Imported settings for %1$s. A backup was created first with key "%2$s" — restore it with rank-math/manage-backup to undo.', 'acrossai-abilities-manager' ),
				implode( ', ', $result['panels_imported'] ),
				$result['backup_key']
			)
			: sprintf(
				/* translators: %s: comma-separated panel names */
				__( 'Imported settings for %s. No backup key was reported, so this change may not be undoable.', 'acrossai-abilities-manager' ),
				implode( ', ', $result['panels_imported'] )
			);

		return $result;
	}
}
