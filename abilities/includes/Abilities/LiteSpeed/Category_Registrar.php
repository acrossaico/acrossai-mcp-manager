<?php
/**
 * Feature 104 — registers the ability category used by all LiteSpeed Cache abilities.
 *
 * Guarded on LiteSpeed being installed so the category is not advertised on sites without it. The
 * presence probe goes through LiteSpeed_Guard so every LiteSpeed symbol in the suite is named in
 * exactly one directory — the same rule Test_LiteSpeed_Architecture enforces for the abilities.
 *
 * Runs on wp_abilities_api_categories_init — before the Library Processor calls
 * wp_register_ability() at wp_abilities_api_init P5. WP core silently drops any ability whose
 * category was not pre-registered (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION), so this must not
 * be reordered relative to the Processor.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\LiteSpeed_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by every ability under includes/Abilities/LiteSpeed/.
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
		if ( ! LiteSpeed_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-litespeed-cache',
			array(
				'label'       => __( 'Acrossai Abilities Manager — LiteSpeed Cache', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for managing LiteSpeed Cache: purging, cache and optimisation settings, lazy loading, the crawler, database reporting, object and browser cache, and configuration presets.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
