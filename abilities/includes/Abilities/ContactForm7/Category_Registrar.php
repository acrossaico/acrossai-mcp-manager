<?php
/**
 * Feature 103 — registers the ability category used by all Contact Form 7 abilities.
 *
 * Guarded on CF7 being installed so the category is not advertised on sites without it.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Contact_Form_7_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by every ability under includes/Abilities/ContactForm7/.
 *
 * Runs on wp_abilities_api_categories_init — before the Library Processor calls
 * wp_register_ability() at wp_abilities_api_init P5. WP core silently drops any ability whose
 * category was not pre-registered (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION), so this must not
 * be reordered relative to the Processor.
 *
 * The presence probe goes through Contact_Form_7_Guard so every CF7 symbol in the suite is named in
 * exactly one directory — the same rule Test_Contact_Form_7_Architecture enforces for the abilities.
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
		if ( ! Contact_Form_7_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-contact-form-7',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Contact Form 7', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for managing Contact Form 7: listing and editing forms, adding and changing fields in a form template, editing both mail templates and their tags, validation messages, per-form behaviour settings, and running Contact Form 7\'s own configuration validator.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
