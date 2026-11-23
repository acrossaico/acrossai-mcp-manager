<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Options
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by Options abilities
 * (wp_options table â get/update/delete/list/search).
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
			'acrossai-options',
			array(
				'label'       => __( 'Acrossai Abilities Manager â Options', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for reading, writing, and searching the wp_options table.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
