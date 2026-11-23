<?php
/**
 * Feature 120 — activate or deactivate a Site Kit module.
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
 * Ability #4 — site-kit/set-module-state.
 *
 * Idempotent by construction: an already-in-state request reports changed:false and
 * touches nothing. Site Kit's own activate_module() returns a WP_Error for an
 * already-active module, which would turn a harmless repeat into a failure.
 *
 * Confirm-gated but not destructive. Deactivating Analytics stops the measurement
 * snippet rendering on the live site, so traffic stops being recorded from that
 * moment — nothing already collected is deleted, but the gap cannot be backfilled.
 */
class Set_Module_State extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'set-module-state';
	}

	protected function ability_label(): string {
		return __( 'Set Site Kit Module State', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Activate or deactivate one Site Kit module. Activating a module does not connect it: it still needs its account and property chosen in Site Kit before it returns data, which site-kit/list-modules reports as connected. Deactivating a module that places a tag — Analytics 4, Tag Manager, AdSense — stops that tag rendering on the live site, so measurement stops from that moment and the gap cannot be recovered later. Force-active modules such as Search Console cannot be switched off. Repeating a request that is already in the requested state changes nothing and reports changed:false.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-modules';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'module' => array(
				'type'        => 'string',
				'description' => __( 'Module slug, e.g. "analytics-4", "adsense", "tagmanager". List them with site-kit/list-modules.', 'acrossai-abilities-manager' ),
			),
			'active' => array(
				'type'        => 'boolean',
				'description' => __( 'True to activate, false to deactivate.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'slug'      => array( 'type' => 'string' ),
			'active'    => array( 'type' => 'boolean' ),
			'changed'   => array( 'type' => 'boolean' ),
			'connected' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Site_Kit_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'module', 'active' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
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

		if ( ! array_key_exists( 'active', $input ) ) {
			return new WP_Error( 'invalid_input', __( 'active is required: true to activate the module, false to deactivate it.', 'acrossai-abilities-manager' ) );
		}

		$result = Module_Repository::set_module_state( $slug, (bool) $input['active'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result['changed'] ) {
			$result['message'] = sprintf(
				/* translators: 1: module slug, 2: state word */
				__( 'The Site Kit "%1$s" module was already %2$s. Nothing changed.', 'acrossai-abilities-manager' ),
				$slug,
				$result['active'] ? __( 'active', 'acrossai-abilities-manager' ) : __( 'inactive', 'acrossai-abilities-manager' )
			);
		} elseif ( $result['active'] ) {
			$result['message'] = $result['connected']
				? sprintf(
					/* translators: %s: module slug */
					__( 'Activated the Site Kit "%s" module. It is connected and can return data.', 'acrossai-abilities-manager' ),
					$slug
				)
				: sprintf(
					/* translators: %s: module slug */
					__( 'Activated the Site Kit "%s" module, but it is not connected yet — an administrator must choose its account and property at Site Kit → Settings before it returns data.', 'acrossai-abilities-manager' ),
					$slug
				);
		} else {
			$result['message'] = sprintf(
				/* translators: %s: module slug */
				__( 'Deactivated the Site Kit "%s" module. If it placed a tag on the site, that tag has stopped rendering and measurement stops from now.', 'acrossai-abilities-manager' ),
				$slug
			);
		}

		return $result;
	}
}
