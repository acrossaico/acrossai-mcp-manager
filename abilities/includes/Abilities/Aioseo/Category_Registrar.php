<?php
/**
 * Feature 132 — registers the ability category used by the All in One SEO suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-all-in-one-seo` on wp_abilities_api_categories_init.
 *
 * WP core silently drops any ability whose category was not pre-registered
 * (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION), so this must keep running before the Library
 * Processor calls wp_register_ability() at wp_abilities_api_init P5.
 *
 * @since 0.0.44
 */
final class Category_Registrar {

	/**
	 * @since 0.0.44
	 * @var   self|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * @since  0.0.44
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registered unconditionally, with no All in One SEO presence check.
	 *
	 * Every other integration here guards its registrar on the host plugin, and two of them were
	 * wrong to: the 0.0.44 backup-suite fix exists because a guarded registrar sitting in front of an
	 * unguarded ability registration drops the whole suite with nothing but a `_doing_it_wrong`
	 * notice to show for it. The abilities in this suite ARE gated on AIOSEO — see the bootstrap — so
	 * the category is simply harmless when AIOSEO is absent: one array entry, never surfaced on its
	 * own, and a registrar that cannot go out of step with the gate it no longer duplicates.
	 *
	 * @since  0.0.44
	 * @return void
	 */
	public function register(): void {
		wp_register_ability_category(
			'acrossai-all-in-one-seo',
			array(
				'label'       => __( 'AcrossAI Abilities Manager — All in One SEO', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for All in One SEO: the typed settings panels AIOSEO exposes no writer for, its per-post-type and per-taxonomy search appearance, sitemap health and regeneration, settings backups, and bulk SEO meta.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
