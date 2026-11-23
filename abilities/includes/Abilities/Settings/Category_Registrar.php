<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Settings
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by all WordPress Settings abilities
 * (Permalinks, Site Identity).
 *
 * Must run on wp_abilities_api_categories_init â before the Library Processor
 * calls wp_register_ability() at wp_abilities_api_init P5.
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
		wp_register_ability_category(
			'acrossai-settings',
			array(
				'label'       => __( 'Acrossai Abilities Manager â Settings', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for reading and updating WordPress Settings: Permalinks, Site Title, Tagline, and Site Icon.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
