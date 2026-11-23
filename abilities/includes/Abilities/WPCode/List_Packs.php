<?php
/**
 * Feature 112 - lists the WPCode snippet packs.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Library_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * lists the WPCode snippet packs.
 *
 * @since 0.0.34
 */
final class List_Packs extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/list-packs';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Snippet Packs', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List WPCode snippet packs, which are themed bundles of library snippets, with how many snippets each contains and whether it is already installed on this site.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'library';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'packs' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'library_connected' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether this site is signed in to the WPCode library.', 'acrossai-abilities-manager' ),
			),
			'connect_url'       => array(
				'type'        => 'string',
				'description' => __( 'When not connected, the wp-admin URL a person should open to sign in. No ability can sign in on their behalf.', 'acrossai-abilities-manager' ),
			),
			'count' => array( 'type' => 'integer' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$packs = Library_Repository::packs();

		if ( is_wp_error( $packs ) ) {
			return $packs;
		}

		$connection = Library_Repository::connection();

		return array(
			'packs'             => $packs,
			'count'             => count( $packs ),
			'library_connected' => (bool) $connection['connected'],
			'connect_url'       => (string) $connection['connect_url'],
			'message'           => Library_Repository::reader_message(
				$connection,
				sprintf(
					/* translators: %d: number of packs. */
					_n( '%d snippet pack available.', '%d snippet packs available.', count( $packs ), 'acrossai-abilities-manager' ),
					count( $packs )
				)
			),
		);
	}
}
