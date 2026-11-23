<?php
/**
 * Feature 107 — registers the ability category used by the Classic Editor suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ClassicEditor
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ClassicEditor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ClassicEditor\Classic_Editor_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-classic-editor` on wp_abilities_api_categories_init.
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
		if ( ! Classic_Editor_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-classic-editor',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Classic Editor', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for the Classic Editor plugin: the effective editor configuration and the layer that decided it, the site-wide default and the per-user switch, which editor a given post will open in and why, and which editors each post type allows.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
