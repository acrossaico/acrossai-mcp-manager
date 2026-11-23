<?php
/**
 * Category_Registrar for the Debugging ability inventory (Feature 061).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

defined( 'ABSPATH' ) || exit;

/**
 * Category_Registrar for the Debugging category (Feature 061).
 *
 * Registers a single ability category shared by every current and future debugging
 * sub-group (Conflict Testing, future log tail, future transient inspection, etc.).
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
			'acrossai-debugging',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Debugging', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for debugging a WordPress site, including conflict testing (toggle plugins on/off without modifying wp_options.active_plugins) and future debugging sub-groups such as log tail, transient inspection, and Query Monitor toggling.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
