<?php
/**
 * Feature 069 — Rank Math module state.
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
 * Static-only reader/writer for Rank Math's module registry.
 *
 * Replicates Admin_Rest::save_module() (includes/rest/class-admin.php:130-138) in
 * full, including the two steps a naive option write omits: the rewrite-rule
 * refresh and the rank_math/module_changed action. Skipping them leaves stale
 * rewrite rules behind — which is precisely the bug class that makes
 * refresh-llms-route necessary as a repair tool.
 */
final class Module_Repository {

	/**
	 * Option holding the active module slugs.
	 *
	 * Recorded for reference only — this class never reads or writes it directly.
	 * Reads go through Helper::is_module_active() and writes through
	 * Helper::update_modules(), so Rank Math owns the storage format.
	 *
	 * @see seo-by-rank-math/includes/class-helper.php:182
	 */
	private const ACTIVE_OPTION = 'rank_math_modules';

	/**
	 * Modules whose rewrite rules must be flushed when their state changes.
	 *
	 * Mirrors Rank Math's private maybe_delete_rewrite_rules().
	 *
	 * @see seo-by-rank-math/includes/rest/class-admin.php:445-449
	 */
	private const REWRITE_MODULES = array( 'sitemap', 'llms-txt' );

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * All registered module slugs.
	 *
	 * @return string[]
	 */
	public static function available(): array {
		$manager = self::manager();
		if ( null === $manager || ! isset( $manager->modules ) || ! is_array( $manager->modules ) ) {
			return array();
		}
		return array_map( 'strval', array_keys( $manager->modules ) );
	}

	/**
	 * Currently active module slugs.
	 *
	 * Derived through Helper::is_module_active() per registered module rather than by
	 * reading the rank_math_modules option directly. The option is only half the
	 * answer: is_module_active() additionally requires the slug to be REGISTERED in
	 * rank_math()->manager->modules, so a stale entry left behind by a removed or
	 * renamed module is correctly reported inactive instead of being echoed back as
	 * active.
	 *
	 * @return string[]
	 */
	public static function active(): array {
		if ( ! class_exists( '\RankMath\Helper' ) ) {
			return array();
		}

		$active = array();
		foreach ( self::available() as $slug ) {
			if ( \RankMath\Helper::is_module_active( $slug ) ) {
				$active[] = $slug;
			}
		}
		return $active;
	}

	/**
	 * All modules with their state.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function listing(): array {
		$manager = self::manager();
		if ( null === $manager || ! isset( $manager->modules ) || ! is_array( $manager->modules ) ) {
			return array();
		}

		$active = self::active();
		$out    = array();

		foreach ( $manager->modules as $slug => $module ) {
			$slug     = (string) $slug;
			$as_array = is_array( $module ) ? $module : array();
			$out[]    = array(
				'id'              => $slug,
				'label'           => isset( $as_array['title'] ) ? (string) $as_array['title'] : $slug,
				'description'     => isset( $as_array['desc'] ) ? (string) $as_array['desc'] : '',
				'active'          => in_array( $slug, $active, true ),
				// Rank Math marks a module unavailable on this install — either a
				// PRO upsell stub or an unmet dependency such as WooCommerce.
				'disabled'        => ! empty( $as_array['disabled'] ),
				'pro'             => ! empty( $as_array['probadge'] ),
				'flushes_rewrite' => in_array( $slug, self::REWRITE_MODULES, true ),
			);
		}

		return $out;
	}

	/**
	 * Enable or disable one module.
	 *
	 * @param string $module Module slug.
	 * @param string $state  'on' or 'off'.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_state( string $module, string $state ) {
		if ( ! class_exists( '\RankMath\Helper' ) ) {
			return new WP_Error( 'rank_math_missing', __( 'Rank Math SEO is not active.', 'acrossai-abilities-manager' ) );
		}
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'state must be "on" or "off".', 'acrossai-abilities-manager' ) );
		}

		$available = self::available();
		if ( array() !== $available && ! in_array( $module, $available, true ) ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: 1: submitted module slug, 2: comma-separated list of registered modules */
					__( 'Unknown Rank Math module "%1$s". Registered modules: %2$s.', 'acrossai-abilities-manager' ),
					$module,
					implode( ', ', $available )
				)
			);
		}

		$was_active = in_array( $module, self::active(), true );

		\RankMath\Helper::update_modules( array( $module => $state ) );

		$flushed = self::maybe_flush_rewrite( $module );

		/**
		 * Rank Math fires this after a module state change; modules hook it to set
		 * themselves up. Omitting it leaves dependants unnotified.
		 *
		 * @see seo-by-rank-math/includes/rest/class-admin.php:135
		 */
		do_action( 'rank_math/module_changed', $module, $state );

		return array(
			'module'          => $module,
			'state'           => $state,
			'was_active'      => $was_active,
			'is_active'       => in_array( $module, self::active(), true ),
			'rewrite_flushed' => $flushed,
		);
	}

	/**
	 * Flush rewrite rules when the module owns any.
	 *
	 * Mirrors Rank Math's private maybe_delete_rewrite_rules(), which deletes the
	 * rewrite_rules option so WordPress regenerates it on the next request.
	 *
	 * @see seo-by-rank-math/includes/rest/class-admin.php:445-449
	 *
	 * @param string $module Module slug.
	 * @return bool Whether rules were flushed.
	 */
	public static function maybe_flush_rewrite( string $module ): bool {
		if ( ! in_array( $module, self::REWRITE_MODULES, true ) ) {
			return false;
		}
		delete_option( 'rewrite_rules' );
		return true;
	}

	/**
	 * Rank Math's module manager, or null when unavailable.
	 *
	 * @return object|null
	 */
	private static function manager(): ?object {
		if ( ! function_exists( 'rank_math' ) ) {
			return null;
		}
		$rank_math = rank_math();
		if ( ! is_object( $rank_math ) || ! isset( $rank_math->manager ) || ! is_object( $rank_math->manager ) ) {
			return null;
		}
		return $rank_math->manager;
	}
}
