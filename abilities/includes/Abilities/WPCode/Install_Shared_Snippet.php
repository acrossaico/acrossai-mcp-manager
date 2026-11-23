<?php
/**
 * Feature 112 - installs a snippet shared by link, inactive.
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
 * installs a snippet shared by link, inactive.
 *
 * @since 0.0.34
 */
final class Install_Shared_Snippet extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/install-shared-snippet';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Install Shared Snippet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Install a snippet someone shared with you by WPCode library link, using the share code from that link. The site must be signed in to the library. As with every library install it lands INACTIVE whatever the payload says, so a person decides whether someone else code runs here. Requires confirm: true.', 'acrossai-abilities-manager' );
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
			'hash' => array(
				'type'        => 'string',
				'minLength'   => 1,
				'description' => __( 'The share code from the WPCode library link.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'hash' );
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
		return __( 'This installs code shared by someone else onto this site. It will be installed inactive and will not run until you activate it. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return array<int, array<string, string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'snippets/get-snippet',
				'reason' => __( 'Read the installed code before activating it. A shared snippet is someone else\'s code and arrives switched off on purpose.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'snippets/activate-snippet',
				'reason' => __( 'Switch it on once you have read it. For php this test-runs the code first and refuses if it errors.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$installed = Library_Repository::install_shared( (string) ( $input['hash'] ?? '' ) );

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		return array(
			'snippet'   => $installed,
			'in_cache'  => $installed['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
			'message'   => __( 'Shared snippet installed and left inactive. Review the code, then call activate-snippet.', 'acrossai-abilities-manager' ),
		);
	}
}
