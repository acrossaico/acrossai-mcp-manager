<?php
/**
 * Feature 055 — WP Abilities API category for admin-menu introspection.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\AdminMenu
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\AdminMenu;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category for admin-menu introspection.
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
			'acrossai-admin-menu',
			array(
				'label'       => __( 'Acrossai Abilities Manager - Admin Menu', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for introspecting the WordPress admin menu: pages, submenus, settings, and current-screen context.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
