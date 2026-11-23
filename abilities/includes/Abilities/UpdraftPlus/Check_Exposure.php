<?php
/**
 * Feature 127 - whether the web server will hand out a backup archive.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\UpdraftPlus
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\UpdraftPlus;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Backup_Exposure;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\UpdraftPlus\Backup_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A backup archive is the whole database. This asks whether anyone can download it.
 *
 * @since 0.0.35
 */
final class Check_Exposure extends Base_UpdraftPlus_Ability {

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function slug(): string {
		return 'updraftplus/check-exposure';
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Check Backup Exposure', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Check whether this site serves its backup directories over HTTP. A backup archive contains the entire database - every password hash, every stored key, every customer address - so a downloadable one is a total compromise. Both common backup plugins drop a .htaccess to prevent this, and on nginx, IIS and Caddy that file is never read, so the protection looks present and does nothing. This asks the running web server for the real URL and reports the real status code rather than assuming.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function sub_group(): string {
		return 'security';
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
			'server'            => array( 'type' => 'string' ),
			'htaccess_honoured' => array(
				'type'        => 'boolean',
				'description' => __( 'False on nginx, IIS and Caddy, where a .htaccess in the backup directory has no effect.', 'acrossai-abilities-manager' ),
			),
			'directories'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'exposed_count'     => array( 'type' => 'integer' ),
			'note'              => array( 'type' => 'string' ),
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

		return Backup_Exposure::scan( 'UpdraftPlus', Backup_Repository::storage_paths() );
	}
}
