<?php
/**
 * Feature 127 - one backup set in full.
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
 * Everything known about one set, including the files it is made of.
 *
 * @since 0.0.35
 */
final class Get_Backup extends Base_UpdraftPlus_Ability {

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function slug(): string {
		return 'updraftplus/get-backup';
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Backup', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report one backup set in full: when it was taken, what components it holds, the archive files it is made of, its size and where it is stored. Use the id exactly as returned by updraftplus/list-backups.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function sub_group(): string {
		return 'inventory';
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id'       => array(
				'type'        => 'string',
				'description' => __( 'Backup identifier from updraftplus/list-backups.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array('id');
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'backup' => array( 'type' => 'object', 'additionalProperties' => true ),
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
		$backup = Backup_Repository::get_backup( (string) $input['id'] );

		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		return array( 'backup' => $backup );
	}
}
