<?php
/**
 * Feature 120 — read one Site Kit module's settings.
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
 * Ability #3 — site-kit/get-module-settings.
 */
class Get_Module_Settings extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-module-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Site Kit Module Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Read the stored settings for one Site Kit module: the Analytics 4 property and measurement IDs, the Search Console property URL, the Tag Manager container IDs, the AdSense client ID, and each module\'s owner and snippet-placement flags. This is how you find out which Google property a site is actually reporting on. Read through Site Kit rather than from the raw option, so defaults and per-module getters are applied the same way Site Kit applies them. Client secrets and ad-blocking recovery snippets are never returned; the redacted list names what was withheld.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-modules';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function input_properties(): array {
		return array(
			'module' => array(
				'type'        => 'string',
				'description' => __( 'Module slug, e.g. "analytics-4", "search-console", "adsense", "tagmanager". List them with site-kit/list-modules.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'slug'     => array( 'type' => 'string' ),
			'settings' => array( 'type' => 'object' ),
			'redacted' => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array( 'module' );
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$slug = isset( $input['module'] ) ? sanitize_key( (string) $input['module'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_input', __( 'module is required. List the available slugs with site-kit/list-modules.', 'acrossai-abilities-manager' ) );
		}

		$result = Module_Repository::get_module_settings( $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: module slug, 2: number of settings returned */
			__( 'Read %2$d settings for the Site Kit "%1$s" module.', 'acrossai-abilities-manager' ),
			$slug,
			count( $result['settings'] )
		);

		return $result;
	}
}
