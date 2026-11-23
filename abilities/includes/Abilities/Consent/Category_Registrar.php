<?php
/**
 * Feature 118 — registers the ability category used by the Consent suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Consent
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Consent;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Consent\Consent_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-consent` on wp_abilities_api_categories_init.
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
		if ( ! Consent_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-consent',
			array(
				'label'       => __( 'AcrossAI Abilities Manager — Cookie Consent', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for the cookie consent banner: the declared cookie list, the consent categories, the banner itself and its settings.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
