<?php
/**
 * Feature 059 — Recovery Mode abilities category.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.17
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by all Recovery Mode abilities.
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
			'acrossai-recovery',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Recovery Mode', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for operating the site around WordPress Recovery Mode: detect if recovery is active, list paused (fatally-erroring) plugins and themes, clear paused entries so WP retries loading them, get the admin-clickable exit URL, and filter recent fatal errors from debug.log.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
