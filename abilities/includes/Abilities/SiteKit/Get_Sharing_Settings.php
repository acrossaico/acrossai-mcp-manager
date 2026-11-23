<?php
/**
 * Feature 120 — read Site Kit's dashboard sharing configuration.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Module_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #5 — site-kit/get-sharing-settings.
 *
 * Read-only on purpose. Dashboard sharing decides which WordPress roles may see
 * another user's Google data through that user's credentials, which is a delegation
 * of access rather than a display preference — a change worth making deliberately in
 * the admin screen rather than through a tool call.
 */
class Get_Sharing_Settings extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-sharing-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Site Kit Dashboard Sharing', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Report Site Kit\'s dashboard sharing configuration: which modules can be shared, which are currently shared, which WordPress roles each is shared with, who may change that, and which user owns each module. Dashboard sharing lets people who have not connected their own Google account read data through the owner\'s credentials, so this answers who can see the site\'s Search Console and Analytics data. Read-only: sharing delegates access to someone\'s Google account and is changed in Site Kit\'s own screens.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-modules';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'per_module'       => array( 'type' => 'object' ),
			'shareable_slugs'  => array( 'type' => 'array' ),
			'shared_slugs'     => array( 'type' => 'array' ),
			'owners_by_module' => array( 'type' => 'object' ),
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
		$result = Module_Repository::get_sharing_settings();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = array() === $result['shared_slugs']
			? __( 'No Site Kit module is shared with any role, so only users with their own connected Google account can see this data.', 'acrossai-abilities-manager' )
			: sprintf(
				/* translators: 1: number of shared modules, 2: number of shareable modules */
				__( '%1$d of %2$d shareable Site Kit modules are shared with other roles.', 'acrossai-abilities-manager' ),
				count( $result['shared_slugs'] ),
				count( $result['shareable_slugs'] )
			);

		return $result;
	}
}
