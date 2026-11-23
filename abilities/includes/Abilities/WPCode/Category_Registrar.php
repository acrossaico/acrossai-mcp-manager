<?php
/**
 * Feature 112 — registers the ability category used by the WPCode suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-wpcode` on wp_abilities_api_categories_init.
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
		if ( ! WPCode_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-wpcode',
			array(
				'label'       => __( 'Acrossai Abilities Manager — WPCode', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for the WPCode plugin: create, edit, place and activate code snippets of every type, manage the global header, body and footer scripts, inspect snippet errors and safe mode, and install snippets from the WPCode library.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
