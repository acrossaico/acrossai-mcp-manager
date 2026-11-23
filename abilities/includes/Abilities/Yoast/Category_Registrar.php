<?php
/**
 * Feature 106 — registers the ability category used by all Yoast SEO abilities.
 *
 * Guarded on Yoast being installed, through Yoast_Guard so the probe lives in one directory. Runs on
 * wp_abilities_api_categories_init — WP core silently drops any ability whose category was not
 * pre-registered (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION).
 *
 * Deliberately NOT gated on the environment, unlike Yoast's own category registrar, which rides on
 * the same production-only conditional as its abilities.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Yoast_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by every ability under includes/Abilities/Yoast/.
 */
final class Category_Registrar {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.34
	 * @var   self|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * Return the singleton instance.
	 *
	 * @since  0.0.34
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the ability category with the WP Abilities API.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function register(): void {
		if ( ! Yoast_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-yoast-seo',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Yoast SEO', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for Yoast SEO: site-wide settings, term and taxonomy SEO, indexables and archives, XML sitemaps, indexation and diagnostics — the surface Yoast\'s own post-scoped abilities do not reach.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
