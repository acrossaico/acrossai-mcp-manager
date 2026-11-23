<?php
/**
 * Feature 116 — registers the ability category used by the Loco Translate suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Loco_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-loco-translate` on wp_abilities_api_categories_init.
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
		if ( ! Loco_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-loco-translate',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Loco Translate', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for Loco Translate: discover translation bundles and text domains, read and write translated strings, and recompile the MO, PHP-cache and JSON artefacts WordPress actually loads.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
