<?php
/**
 * Feature 069 — registers the ability category used by all Rank Math abilities.
 *
 * Guarded on Rank Math being installed so the category is not advertised on
 * sites without Rank Math.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by every ability under
 * includes/Abilities/RankMath/.
 *
 * Runs on wp_abilities_api_categories_init — before the Library Processor
 * calls wp_register_ability() at wp_abilities_api_init P5. WP core silently
 * drops any ability whose category was not pre-registered, so this must not be
 * reordered relative to the Processor.
 *
 * The register() method short-circuits when Rank Math is not loaded so the
 * category is silently absent on non-Rank-Math sites (Feature 069 FR-003).
 *
 * \RankMath\Helper is used as the presence probe rather than a version
 * constant: it is the facade every ability ultimately reaches through, so its
 * presence also proves Rank Math's autoloader is live.
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
		if ( ! class_exists( '\RankMath\Helper' ) ) {
			return;
		}
		wp_register_ability_category(
			'acrossai-rank-math',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Rank Math', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for managing Rank Math SEO: typed global settings, per-post and bulk SEO metadata, schema, primary terms, content audits, redirections, 404 logs, sitemaps, llms.txt routes, Instant Indexing, the Role Manager, status and maintenance tools, Search Console analytics, Content AI, and AI Visibility.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
