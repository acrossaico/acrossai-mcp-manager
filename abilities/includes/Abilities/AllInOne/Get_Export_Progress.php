<?php
/**
 * Feature 127 - how a running backup is getting on.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\AllInOne
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\AllInOne;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\AllInOne\Archive_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A backup runs in the background; this is how its progress is read.
 *
 * @since 0.0.35
 */
final class Get_Export_Progress extends Base_All_In_One_Ability {

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function slug(): string {
		return 'all-in-one/get-export-progress';
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Backup Progress', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report on a backup started by all-in-one/start-export. A backup is not finished when it is started - it runs in the background - so this is how to find out whether it is still going, whether it finished, and which backup set it produced. Pass the job_id that start-backup returned.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function sub_group(): string {
		return 'operate';
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'job_id'   => array(
				'type'        => 'string',
				'description' => __( 'Job identifier returned by all-in-one/start-export.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array('job_id');
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'job_id'       => array( 'type' => 'string' ),
			'running'      => array( 'type' => 'boolean' ),
			'finished'     => array( 'type' => 'boolean' ),
			'backup_id'    => array( 'type' => array( 'string', 'null' ) ),
			'last_message' => array( 'type' => 'string' ),
			'note'         => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.35
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Archive_Repository::job_progress( (string) $input['job_id'] );
	}
}
