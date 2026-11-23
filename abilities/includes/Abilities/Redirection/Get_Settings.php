<?php
/**
 * Feature 130 — read Redirection's settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Redirection
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Redirection;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Redirection\Redirects_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * redirects/get-settings — Redirection's options, secrets excluded.
 *
 * Read-only, idempotent. Reports whether Redirection auto-creates a redirect
 * when a post slug changes (monitor_post / auto_target), redirect expiry, what
 * it logs, and the active modules. The API token is never returned.
 */
class Get_Settings extends Base_Redirection_Ability {

	protected function slug(): string {
		return 'get-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Redirection Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return Redirection\'s settings: whether it auto-creates a redirect when a post slug changes, how long logs and 404s are kept, what it logs, the HTTP header handling and the active modules. The REST API token is never included. Read-only.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'redirects-settings';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'settings' => array( 'type' => 'object', 'additionalProperties' => true ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$settings = Redirects_Repository::settings();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		return array(
			'settings' => $settings,
			'message'  => __( 'Read Redirection settings.', 'acrossai-abilities-manager' ),
		);
	}
}
