<?php
/**
 * Feature 069 — Rank Math role capability matrix.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only reader for Rank Math's role capabilities, plus the reset.
 *
 * There is deliberately NO bulk writer. Helper::set_capabilities() iterates every
 * registered role and removes each Rank Math capability absent from the payload
 * (includes/helpers/class-wordpress.php:219), so a partial payload silently strips
 * capabilities from roles the caller never mentioned. The plugin already ships
 * users/add-role-capability and users/remove-role-capability, which write one
 * capability at a time and cannot trigger that — and rank_math_* capabilities are
 * ordinary WordPress capabilities, so those abilities work on them directly.
 *
 * What is NOT otherwise available is discovery: knowing which sixteen capabilities
 * Rank Math even defines, and how they are currently distributed. That is what
 * matrix() provides.
 */
final class Role_Capability_Repository {

	/**
	 * Module slug.
	 */
	public const MODULE = 'role-manager';

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The registered Rank Math capabilities, as capability => human title.
	 *
	 * Rank Math's accessor is Capability_Manager::get(), not ::instance().
	 *
	 * @return array<string,string>
	 */
	public static function capabilities(): array {
		if ( ! class_exists( '\RankMath\Role_Manager\Capability_Manager' ) ) {
			return array();
		}
		$manager = \RankMath\Role_Manager\Capability_Manager::get();
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_capabilities' ) ) {
			return array();
		}
		$caps = $manager->get_capabilities();
		return is_array( $caps ) ? array_map( 'strval', $caps ) : array();
	}

	/**
	 * The roles x Rank Math capabilities matrix.
	 *
	 * @param string $role Optional single role slug; all roles when empty.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function matrix( string $role = '' ) {
		if ( ! class_exists( '\RankMath\Helper' ) ) {
			return new WP_Error( 'rank_math_missing', __( 'Rank Math SEO is not active.', 'acrossai-abilities-manager' ) );
		}

		$capabilities = self::capabilities();
		if ( array() === $capabilities ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'Rank Math did not report any capabilities. The Role Manager module may be inactive.', 'acrossai-abilities-manager' ) );
		}

		$granted = \RankMath\Helper::get_roles_capabilities();
		$granted = is_array( $granted ) ? $granted : array();

		if ( '' !== $role ) {
			if ( null === get_role( $role ) ) {
				return new WP_Error(
					'not_found',
					sprintf(
						/* translators: %s: role slug */
						__( 'The role "%s" is not registered.', 'acrossai-abilities-manager' ),
						$role
					)
				);
			}
			$granted = array_intersect_key( $granted, array( $role => true ) );
		}

		$roles = array();
		foreach ( $granted as $slug => $role_caps ) {
			$role_caps = is_array( $role_caps ) ? $role_caps : array();
			$map       = array();
			foreach ( array_keys( $capabilities ) as $cap ) {
				$map[ $cap ] = ! empty( $role_caps[ $cap ] );
			}
			$roles[ (string) $slug ] = $map;
		}

		$catalogue = array();
		foreach ( $capabilities as $cap => $title ) {
			$catalogue[] = array(
				'capability' => $cap,
				// The suffix our abilities use, e.g. 'titles' for rank_math_titles.
				'suffix'     => preg_replace( '/^rank_math_/', '', $cap ),
				'label'      => $title,
			);
		}

		return array(
			'capabilities' => $catalogue,
			'roles'        => $roles,
			'role_count'   => count( $roles ),
			'cap_count'    => count( $catalogue ),
		);
	}

	/**
	 * Restore Rank Math's default capability distribution for every role.
	 *
	 * Distinct from the plugin's users/reset-role, which restores a role to its
	 * WordPress defaults and would therefore strip Rank Math capabilities rather
	 * than restore them.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function reset() {
		if ( ! class_exists( '\RankMath\Role_Manager\Capability_Manager' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math Role Manager module is not available.', 'acrossai-abilities-manager' ) );
		}
		$manager = \RankMath\Role_Manager\Capability_Manager::get();
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'reset_capabilities' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'This Rank Math build does not expose a capability reset.', 'acrossai-abilities-manager' ) );
		}

		$before = self::matrix();
		$manager->reset_capabilities();
		$after = self::matrix();

		return array(
			'roles_reset' => is_array( $after ) ? (int) $after['role_count'] : 0,
			'before'      => is_array( $before ) ? $before['roles'] : array(),
			'after'       => is_array( $after ) ? $after['roles'] : array(),
		);
	}
}
