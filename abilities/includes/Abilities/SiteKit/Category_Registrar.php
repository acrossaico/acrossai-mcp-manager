<?php
/**
 * Feature 120 — registers the ability category used by all Site Kit abilities.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Site_Kit_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by every ability under
 * includes/Abilities/SiteKit/.
 *
 * Runs on wp_abilities_api_categories_init — before the Library Processor calls
 * wp_register_ability() at wp_abilities_api_init P5. WP core silently drops any
 * ability whose category was not pre-registered, so this must not be reordered
 * relative to the Processor.
 *
 * register() short-circuits when Site Kit is absent so the category is silently
 * missing on sites without it.
 */
final class Category_Registrar {

	/** @var self|null */
	protected static $instance = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * Return the singleton instance.
	 *
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
	 * @return void
	 */
	public function register(): void {
		if ( ! Site_Kit_Context::available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-site-kit',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Site Kit', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for Site Kit by Google: connection and setup status, module activation and dashboard sharing, and reads of Search Console search analytics, Analytics 4 reports, PageSpeed Insights and AdSense earnings through Site Kit\'s own Google connection.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
