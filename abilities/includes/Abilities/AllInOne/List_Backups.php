<?php
/**
 * Feature 127 - enumerate the backup sets that exist.
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
 * What could actually be restored from, newest first.
 *
 * @since 0.0.35
 */
final class List_Backups extends Base_All_In_One_Ability {

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function slug(): string {
		return 'all-in-one/list-backups';
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Backups', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List the backup sets held on this site, newest first, with when each was taken, how old it is, what it contains, its size on disk and any label. Covers every active backup plugin unless one is named. Sets held only on remote storage are not listed - this reads what is on this server.', 'acrossai-abilities-manager' );
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
			'limit'    => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
			'offset'   => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
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
			'backups' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'count'   => array( 'type' => 'integer' ),
			'total'   => array( 'type' => 'integer' ),
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
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

		return Archive_Repository::list_backups( $limit, $offset );
	}
}
