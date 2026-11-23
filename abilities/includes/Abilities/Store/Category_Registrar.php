<?php
/**
 * Feature 121 — registers the ability category used by the Consent suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Store_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-store` on wp_abilities_api_categories_init.
 *
 * WP core silently drops any ability whose category was not pre-registered
 * (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION), so this must keep running before the Library
 * Processor calls wp_register_ability() at wp_abilities_api_init P5.
 */
final class Category_Registrar {

	/**
	 * @since 0.0.34
	 * @var   self|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
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
	 * @since  0.0.34
	 * @return void
	 */
	public function register(): void {
		if ( ! Store_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-store',
			array(
				'label'       => __( 'AcrossAI Abilities Manager — Store', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for the store: the catalogue, stock, orders, customers and store health.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
