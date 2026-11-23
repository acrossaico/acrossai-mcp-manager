<?php
/**
 * Feature 132 — list AIOSEO settings backups.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Status_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/list-backups.
 *
 * Read-only, idempotent.
 *
 * @since 0.0.44
 */
class List_Backups extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'list-backups';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List All in One SEO Settings Backups', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List the All in One SEO settings backups stored on this site, newest first, with the key and creation time of each. Pass a key to aioseo/manage-backup to restore or delete it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-status';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return 'aioseo_tools_settings';
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'backups' => array( 'type' => 'array' ),
			'count'   => array( 'type' => 'integer' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$backups = Status_Repository::backups();

		return array(
			'backups' => $backups,
			'count'   => count( $backups ),
			'message' => array() === $backups
				? __( 'There are no All in One SEO settings backups on this site. Take one with aioseo/create-backup before any bulk settings change.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of backups */
					_n( '%d settings backup.', '%d settings backups.', count( $backups ), 'acrossai-abilities-manager' ),
					count( $backups )
				),
		);
	}
}
