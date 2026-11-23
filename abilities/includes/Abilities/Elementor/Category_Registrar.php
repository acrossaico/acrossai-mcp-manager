<?php
/**
 * Feature 067 — registers the ability category used by all Elementor abilities.
 *
 * Guarded on Elementor being installed so the category is not advertised on
 * sites without Elementor.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by every ability under
 * includes/Abilities/Elementor/.
 *
 * Runs on wp_abilities_api_categories_init — before the Library Processor
 * calls wp_register_ability() at wp_abilities_api_init P5.
 *
 * The register() method short-circuits when Elementor is not loaded so the
 * category is silently absent on non-Elementor sites (Feature 067 R1 gate).
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
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		wp_register_ability_category(
			'acrossai-elementor',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Elementor', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for managing Elementor page-builder documents: widget-schema discovery, element operations, template CRUD, kit and theme-builder management, design audits, and Elementor Pro custom-code and form-submission management.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
