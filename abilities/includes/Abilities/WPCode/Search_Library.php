<?php
/**
 * Feature 112 - searches the WPCode hosted snippet library.
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
 * searches the WPCode hosted snippet library.
 *
 * @since 0.0.34
 */
final class Search_Library extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/search-library';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Search Snippet Library', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Search WPCode hosted library of ready-made snippets by title, note or category. Returns the library id needed by install-library-snippet. Nothing is installed or run by searching.', 'acrossai-abilities-manager' );
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
		return array(
			'search'   => array(
				'type'        => 'string',
				'description' => __( 'Free text to match against title, note and category. Omit to list everything.', 'acrossai-abilities-manager' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => 25,
				'description' => __( 'Maximum rows to return.', 'acrossai-abilities-manager' ),
			),
		);
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
			'results' => array(
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
				'slug'   => 'snippets/get-library-snippet',
				'reason' => __( 'Read one result in full before installing it.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'snippets/install-library-snippet',
				'reason' => __( 'Install a result. It always lands inactive, whatever the library says.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$results = Library_Repository::search(
			isset( $input['search'] ) ? (string) $input['search'] : '',
			isset( $input['per_page'] ) ? (int) $input['per_page'] : 25
		);

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$connection = Library_Repository::connection();

		return array(
			'results'           => $results,
			'count'             => count( $results ),
			'library_connected' => (bool) $connection['connected'],
			'connect_url'       => (string) $connection['connect_url'],
			'message'           => Library_Repository::reader_message(
				$connection,
				sprintf(
					/* translators: %d: number of results. */
					_n( '%d library snippet matched.', '%d library snippets matched.', count( $results ), 'acrossai-abilities-manager' ),
					count( $results )
				)
			),
		);
	}
}
