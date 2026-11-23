<?php
/**
 * Feature 105 — registers the ability category used by all ACF abilities.
 *
 * Guarded on ACF being installed so the category is not advertised on sites without it. The presence
 * probe goes through Acf_Guard so every ACF symbol in the suite is named in one directory.
 *
 * Runs on wp_abilities_api_categories_init — before the Library Processor calls
 * wp_register_ability(). WP core silently drops any ability whose category was not pre-registered
 * (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Acf_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by every ability under includes/Abilities/Acf/.
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
		if ( ! Acf_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-acf',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Advanced Custom Fields', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for Advanced Custom Fields: reading and writing field values on posts, users, terms, comments and options pages; repeater and flexible-content rows; and ACF block registration and editing.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
