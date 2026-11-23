<?php
/**
 * Feature 120 — list Site Kit modules and their state.
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
 * Ability #2 — site-kit/list-modules.
 */
class List_Modules extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'list-modules';
	}

	protected function ability_label(): string {
		return __( 'List Site Kit Modules', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'List every module Site Kit knows about — Search Console, Analytics 4, PageSpeed Insights, AdSense, Tag Manager, Ads, Sign in with Google, Reader Revenue Manager — with whether each is active, connected, shareable, force-active and which WordPress user owns it. Active means switched on; connected means it also has the account and property settings its API calls need, which is the distinction that explains why a module can be on and still return nothing. Use this to find the right module slug before calling site-kit/get-module-settings, site-kit/set-module-state or site-kit/get-module-data.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-modules';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	/**
	 * Readable before setup: knowing which modules exist is how a caller works out
	 * what setting Site Kit up would even give them.
	 */
	protected function requires_setup(): bool {
		return false;
	}

	protected function input_properties(): array {
		return array(
			'only_active'    => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Return only modules that are switched on.', 'acrossai-abilities-manager' ),
			),
			'only_connected' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Return only modules that are both active and fully configured, which is the set that can actually return data.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'modules'         => array( 'type' => 'array' ),
			'total'           => array( 'type' => 'integer' ),
			'active_count'    => array( 'type' => 'integer' ),
			'connected_count' => array( 'type' => 'integer' ),
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
		$modules = Module_Repository::list_modules();
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		// Counted before filtering: "3 of 9 connected" is the useful summary, and it
		// would be wrong if the counts described the filtered subset.
		$active    = count( array_filter( $modules, static fn( array $m ): bool => (bool) $m['active'] ) );
		$connected = count( array_filter( $modules, static fn( array $m ): bool => (bool) $m['connected'] ) );
		$total     = count( $modules );

		if ( ! empty( $input['only_connected'] ) ) {
			$modules = array_filter( $modules, static fn( array $m ): bool => (bool) $m['connected'] );
		} elseif ( ! empty( $input['only_active'] ) ) {
			$modules = array_filter( $modules, static fn( array $m ): bool => (bool) $m['active'] );
		}

		return array(
			'modules'         => array_values( $modules ),
			'total'           => $total,
			'active_count'    => $active,
			'connected_count' => $connected,
			'message'         => sprintf(
				/* translators: 1: total modules, 2: active count, 3: connected count */
				__( 'Site Kit has %1$d modules: %2$d active, %3$d fully connected.', 'acrossai-abilities-manager' ),
				$total,
				$active,
				$connected
			),
		);
	}
}
