<?php
/**
 * Feature 120 — Site Kit connection status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Status_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #1 — site-kit/get-status.
 *
 * The only ability in the suite that requires neither setup nor authentication, and
 * that is the point: every other ability fails with "not set up" or "not connected",
 * and this is the one that explains which, whose problem it is, and where to go.
 */
class Get_Status extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-status';
	}

	protected function ability_label(): string {
		return __( 'Get Site Kit Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Report whether Site Kit by Google is set up and connected: the plugin version, whether setup was completed for the site, whether the WordPress user running this ability has connected their own Google account, which Google account that is, whether Google has verified site ownership, and which modules are active and connected. Also returns next_step, a plain sentence naming the one thing to do next. Run this first whenever another Site Kit ability reports that something is not connected — Site Kit stores one Google token per WordPress user, so a site can be fully set up while the current user still has no access.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-status';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	/**
	 * Deliberately exempt from both gates.
	 *
	 * Requiring setup here would mean the ability that reports "setup is not complete"
	 * could not run on a site where setup is not complete.
	 */
	protected function requires_setup(): bool {
		return false;
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'site_kit_version'   => array( 'type' => 'string' ),
			'setup_completed'    => array( 'type' => 'boolean' ),
			'user_authenticated' => array( 'type' => 'boolean' ),
			'google_account'     => array( 'type' => 'object' ),
			'site_verified'      => array( 'type' => 'boolean' ),
			'connect_url'        => array( 'type' => 'string' ),
			'disconnect_url'     => array( 'type' => 'string' ),
			'dashboard_url'      => array( 'type' => 'string' ),
			'settings_url'       => array( 'type' => 'string' ),
			'current_user_id'    => array( 'type' => 'integer' ),
			'active_modules'     => array( 'type' => 'array' ),
			'connected_modules'  => array( 'type' => 'array' ),
			'modules_error'      => array( 'type' => 'string' ),
			'next_step'          => array( 'type' => 'string' ),
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
	 * @return array<string,mixed>
	 */
	protected function run( array $input ) {
		$status            = Status_Repository::status();
		$status['message'] = $status['next_step'];

		return $status;
	}
}
