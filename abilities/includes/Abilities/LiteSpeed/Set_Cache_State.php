<?php
/**
 * Feature 104 — Turn Caching On Or Off.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Settings_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/set-cache-state — Turn Caching On Or Off.
 */
final class Set_Cache_State extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'set-cache-state';
	}

	protected function ability_label(): string {
		return __( 'Turn Caching On Or Off', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Turn LiteSpeed page caching on or off site-wide. Confirm-gated when disabling: the site keeps working and simply stops being cached, with nothing on the front end to say so, so a site can sit uncached for weeks before anyone notices. Enabling does not need confirmation.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/get-cache-status',
		);
	}

	protected function input_properties(): array {
		return array(
			'enabled' => array(
				'type'        => 'boolean',
				'description' => __( 'True to cache, false to stop caching.', 'acrossai-abilities-manager' ),
			),
			'confirm' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Required only when disabling the cache.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'enabled',
		);
	}

	protected function output_properties(): array {
		return array(
			'enabled' => array( 'type' => 'boolean' ),

			'changed' => array( 'type' => 'boolean' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$enabled = ! empty( $input['enabled'] );

		// Confirmation is required only for the direction that silently degrades the site. A generic
		// requires_confirmation() would also gate turning caching ON, which protects nothing.
		if ( ! $enabled && empty( $input['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				__( 'Disabling the cache stops every page being cached, and nothing on the site will indicate that. Pass confirm: true to proceed.', 'acrossai-abilities-manager' )
			);
		}

		$updated = Settings_Repository::write( 'cache-general', array( 'cache' => $enabled ) );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'enabled' => (bool) Settings_Repository::value( 'cache' ),
			'changed' => array() !== $updated,
			'message' => $enabled
				? __( 'LiteSpeed caching is on.', 'acrossai-abilities-manager' )
				: __( 'LiteSpeed caching is now OFF. Nothing is being cached.', 'acrossai-abilities-manager' ),
		);
	}
}
