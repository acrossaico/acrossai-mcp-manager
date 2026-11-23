<?php
/**
 * Feature 055 — WP Abilities API category for content search / indexing /
 * internal-link suggestions.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContentSearch
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContentSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category for content-search / indexing /
 * internal-link suggestions.
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
	 */
	public function register(): void {
		wp_register_ability_category(
			'acrossai-content-search',
			array(
				'label'       => __( 'Acrossai Abilities Manager - Content Search', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for content indexing, search, related-content discovery, and internal-link suggestion management (option-backed suggestion queue).', 'acrossai-abilities-manager' ),
			)
		);
	}
}
