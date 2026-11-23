<?php
/**
 * Feature 112 - lists installed library snippets with a newer version available.
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
 * lists installed library snippets with a newer version available.
 *
 * @since 0.0.34
 */
final class List_Snippet_Updates extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/list-snippet-updates';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Snippet Updates', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List the snippets on this site that came from the WPCode library and have a newer version upstream. Nothing on a WordPress site tells you this: a library snippet is copied in once and then never changes, so a fix published upstream never arrives unless someone checks. Each row reports the local version and whether the snippet is currently active, so you can judge the risk before pulling.', 'acrossai-abilities-manager' );
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
			'updates' => array(
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
			'count'   => array( 'type' => 'integer' ),
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
	 * @return array<int, array<string, string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'snippets/update-snippet-from-library',
				'reason' => __( 'Pull the newer version for one of these. It replaces the code entirely, so a local edit is lost; the snippet keeps whatever active state it has here.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'snippets/get-snippet',
				'reason' => __( 'Read the current code first, so you can see what an update would replace.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$updates = Library_Repository::updates();

		if ( is_wp_error( $updates ) ) {
			return $updates;
		}

		$connection = Library_Repository::connection();

		return array(
			'updates'           => $updates,
			'count'             => count( $updates ),
			'library_connected' => (bool) $connection['connected'],
			'connect_url'       => (string) $connection['connect_url'],
			'message'           => Library_Repository::reader_message(
				$connection,
				sprintf(
					/* translators: %d: number of snippets with updates. */
					_n( '%d installed snippet has a newer version.', '%d installed snippets have newer versions.', count( $updates ), 'acrossai-abilities-manager' ),
					count( $updates )
				)
			),
		);
	}
}
