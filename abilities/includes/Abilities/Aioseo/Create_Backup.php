<?php
/**
 * Feature 132 — take an AIOSEO settings backup.
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
 * Ability — aioseo/create-backup.
 *
 * Cheap, safe, and the reason `aioseo/update-settings-panel` does not need a confirmation gate: take
 * one of these before a bulk settings change and the whole change is reversible in a single call.
 *
 * NOT idempotent, and not for a hidden reason: AIOSEO keys each backup by `time()`, so two calls in
 * the same second produce one backup, and two calls a second apart produce two.
 *
 * @since 0.0.44
 */
class Create_Backup extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'create-backup';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Create All in One SEO Settings Backup', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Snapshot the current All in One SEO settings so they can be restored later. Cheap and safe — take one before any bulk settings change, and the whole change becomes reversible with aioseo/manage-backup. Each call adds another backup, except that AIOSEO keys them by the second, so two calls within the same second produce one.', 'acrossai-abilities-manager' );
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
			'backup' => array( 'type' => 'object' ),
			'total'  => array( 'type' => 'integer' ),
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
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Status_Repository::create_backup();
	}
}
