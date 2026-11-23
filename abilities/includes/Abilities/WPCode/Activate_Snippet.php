<?php
/**
 * Feature 112 - switches a WPCode snippet on, and reports honestly when WPCode refuses.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Snippet_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * switches a WPCode snippet on, and reports honestly when WPCode refuses.
 *
 * @since 0.0.34
 */
final class Activate_Snippet extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/activate-snippet';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Activate Code Snippet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Switch a WPCode snippet on. For php and universal snippets WPCode test-runs the code first and, if it errors, quietly leaves the snippet switched off while still reporting a successful save. This ability reads the state back afterwards and returns activation_refused with the recorded error in that case, rather than claiming the snippet is live. Activating executable code requires confirm: true.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'snippets';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array(
				'type'        => 'integer',
				'description' => __( 'Snippet id.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'id' );
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
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * Only executable snippets need confirming; css and text cannot run anything.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		$snippet = Snippet_Repository::find( (int) ( $input['id'] ?? 0 ) );

		if ( is_wp_error( $snippet ) ) {
			return false;
		}

		return in_array( (string) $snippet->get_code_type(), WPCode_Guard::EXECUTED_TYPES, true );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This snippet contains code WPCode will execute on the site. Pass confirm: true to switch it on.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$snippet = Snippet_Repository::find( (int) ( $input['id'] ?? 0 ) );

		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$allowed = WPCode_Guard::assert_code_type( (string) $snippet->get_code_type() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$snippet->active = true;

		// expect_active: persist() reads the state back and reports activation_refused when
		// run_activation_checks() rejected the code and WPCode silently saved it switched off.
		$saved = Snippet_Repository::persist( $snippet, true );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$message = WPCode_Guard::safe_mode()
			? __( 'Snippet activated, but safe mode is on for this site so no snippet is executing right now.', 'acrossai-abilities-manager' )
			: __( 'Snippet activated and present in the loader cache.', 'acrossai-abilities-manager' );

		return array(
			'snippet'   => $saved,
			'in_cache'  => $saved['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
			'message'   => $message,
		);
	}
}
