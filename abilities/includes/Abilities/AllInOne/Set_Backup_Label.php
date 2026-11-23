<?php
/**
 * Feature 127 - name an archive so it can be recognised later.
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
 * Everything known about one set, including the files it is made of.
 *
 * @since 0.0.35
 */
final class Set_Backup_Label extends Base_All_In_One_Ability {

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function slug(): string {
		return 'all-in-one/set-backup-label';
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Set Backup Label', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Attach a label to an archive, so a list of timestamps becomes a list of reasons - \"before the price change\", \"before the theme swap\". Pass an empty label to remove one. UpdraftPlus stores no label against a backup, so this exists only here.', 'acrossai-abilities-manager' );
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
			'id'    => array(
				'type'        => 'string',
				'description' => __( 'Archive filename from all-in-one/list-backups.', 'acrossai-abilities-manager' ),
			),
			'label' => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'The label. Pass an empty string to remove it.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array('id', 'label');
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'backup_id' => array( 'type' => 'string' ),
			'label'     => array( 'type' => 'string' ),
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
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.35
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Archive_Repository::set_label( (string) $input['id'], (string) $input['label'] );
	}
}
