<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Cache
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by all cache-management abilities.
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
			'acrossai-cache',
			array(
				'label'       => __( 'Acrossai Abilities Manager â Cache Management', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for managing WordPress caches: flush object cache and related cache stores.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
