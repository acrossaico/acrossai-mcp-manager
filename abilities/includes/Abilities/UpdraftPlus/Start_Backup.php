<?php
/**
 * Feature 127 - take a backup now.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\UpdraftPlus
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\UpdraftPlus;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\UpdraftPlus\Backup_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Starts a background job. It is not finished when this returns.
 *
 * @since 0.0.35
 */
final class Start_Backup extends Base_UpdraftPlus_Ability {

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function slug(): string {
		return 'updraftplus/start-backup';
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Start Backup', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Start a backup of this site now. The work runs in the background and is NOT finished when this returns - it returns a job_id to poll with updraftplus/get-backup-progress, and the new set appears in updraftplus/list-backups only once it completes. On a quiet site the job may need a visitor before WordPress cron advances it. Take a backup before any change that would be hard to undo.', 'acrossai-abilities-manager' );
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
			'files'          => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Include the files (uploads, themes, plugins).', 'acrossai-abilities-manager' ),
			),
			'database'       => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Include the database.', 'acrossai-abilities-manager' ),
			),
			'send_to_remote' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Also upload to the configured remote storage, where one is set up.', 'acrossai-abilities-manager' ),
			),
			'label'          => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'Optional note stored with the backup, e.g. why it was taken.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'job_id'   => array( 'type' => 'string' ),
			'started'  => array( 'type' => 'boolean' ),
			'includes' => array( 'type' => 'object', 'additionalProperties' => true ),
			'note'     => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.35
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Backup_Repository::start_backup( $input );
	}
}
