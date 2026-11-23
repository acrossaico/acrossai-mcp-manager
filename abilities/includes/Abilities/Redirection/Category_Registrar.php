<?php
/**
 * Feature 130 — registers the ability category used by all Redirection abilities.
 *
 * Guarded on the Redirection plugin being loaded so the category is not
 * advertised on sites without it.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Redirection
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Redirection;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by every ability under
 * includes/Abilities/Redirection/.
 *
 * Runs on wp_abilities_api_categories_init — before the Library Processor calls
 * wp_register_ability() at wp_abilities_api_init P5. WP core silently drops any
 * ability whose category was not pre-registered, so this must not be reordered
 * relative to the Processor.
 *
 * \Red_Item is the presence probe — the model every ability reaches through, so
 * its presence also proves Redirection's includes are live.
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
		if ( ! class_exists( '\Red_Item' ) ) {
			return;
		}
		wp_register_ability_category(
			'acrossai-redirects',
			array(
				'label'       => __( 'Acrossai Abilities Manager — Redirection', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for the Redirection plugin: create, list, update and delete redirects, read the 404 error log and the redirect hit log, list redirect groups, and read Redirection\'s settings.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
