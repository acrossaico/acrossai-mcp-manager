<?php
/**
 * Feature 112 - installs a library snippet, inactive.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Library_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * installs a library snippet, inactive.
 *
 * @since 0.0.34
 */
final class Install_Library_Snippet extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/install-library-snippet';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Install Library Snippet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Install a snippet from WPCode hosted library onto this site. It is always installed INACTIVE, whatever the library says, so that a human decides whether third-party code runs here. Requires confirm: true, and requires the site to be signed in to the WPCode library: searching works without that, but fetching a snippet body does not. Read it first with get-library-snippet, then activate it with activate-snippet once you are satisfied.', 'acrossai-abilities-manager' );
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
			'library_id' => array(
				'type'        => 'integer',
				'description' => __( 'Library snippet id, from search-library.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'library_id' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'snippet'   => array( 'type' => 'object', 'additionalProperties' => true ),
			'in_cache'  => array( 'type' => 'boolean' ),
			'safe_mode' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This installs third-party code from the WPCode library onto this site. It will be installed inactive and will not run until you activate it. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$installed = Library_Repository::install( (int) ( $input['library_id'] ?? 0 ) );

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		return array(
			'snippet'   => $installed,
			'in_cache'  => $installed['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
			'message'   => __( 'Library snippet installed and left inactive. Review the code, then call activate-snippet.', 'acrossai-abilities-manager' ),
		);
	}
}
