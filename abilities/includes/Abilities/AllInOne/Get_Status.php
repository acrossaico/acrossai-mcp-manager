<?php
/**
 * Feature 127 - the headline question: can this site be recovered.
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
 * Whether this site is backed up, how recently, and whether it worked.
 *
 * @since 0.0.35
 */
final class Get_Status extends Base_All_In_One_Ability {

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function slug(): string {
		return 'all-in-one/get-status';
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Backup Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report whether this site can be recovered. For every active backup plugin: when it last ran, whether that run succeeded, how many backup sets exist, whether one is running now, what is scheduled next, how long sets are kept, and which remote destinations are configured by name. Run this before any bulk or destructive change - it is the question of whether the change can be undone. Remote storage is named and never its credentials.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function sub_group(): string {
		return 'state';
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(

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
			'backup_count'       => array( 'type' => 'integer' ),
			'last_backup_time'   => array( 'type' => array( 'integer', 'null' ) ),
			'last_backup_gmt'    => array( 'type' => array( 'string', 'null' ) ),
			'last_backup_age'    => array( 'type' => array( 'string', 'null' ) ),
			'last_backup_result' => array(
				'type'        => array( 'boolean', 'null' ),
				'description' => __( 'Whether the last run succeeded. Null when the plugin records no outcome, which is not the same as a failure.', 'acrossai-abilities-manager' ),
			),
			'last_backup_errors' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'running'            => array( 'type' => 'boolean' ),
			'schedules'          => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'retention'          => array( 'type' => array( 'object', 'array' ), 'additionalProperties' => true ),
			'remote_storage'     => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Destination names only. Their credentials are never read.', 'acrossai-abilities-manager' ),
			),
			'storage_path'       => array( 'type' => 'string' ),
			'any_backup'         => array( 'type' => 'boolean' ),
			'note'               => array( 'type' => 'string' ),
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
		unset( $input );

		$status = Archive_Repository::status();

		$status['any_backup'] = ! empty( $status['backup_count'] );
		// status() already explains what this plugin does not record. Append rather than replace:
		// "no archive exists" and "no outcome is stored for the archives that do" are both true and
		// are different pieces of news.
		$existing = isset( $status['note'] ) ? (string) $status['note'] : '';

		$status['note'] = empty( $status['backup_count'] )
			? trim( __( 'All-in-One WP Migration is active but holds no archive, so there is nothing to restore from. Take one with all-in-one/start-export before making changes that would be hard to undo.', 'acrossai-abilities-manager' ) . ' ' . $existing )
			: $existing;

		return $status;
	}
}
