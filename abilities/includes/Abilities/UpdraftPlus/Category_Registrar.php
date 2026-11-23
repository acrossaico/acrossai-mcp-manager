<?php
/**
 * Feature 127 — registers the ability category used by the UpdraftPlus suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\UpdraftPlus
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\UpdraftPlus;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-updraftplus` on wp_abilities_api_categories_init.
 *
 * WP core silently drops any ability whose category was not pre-registered
 * (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION), so this must keep running before the Library
 * Processor calls wp_register_ability() at wp_abilities_api_init P5.
 */
final class Category_Registrar {

	/**
	 * @since 0.0.35
	 * @var   self|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * @since  0.0.35
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registered unconditionally, with no UpdraftPlus presence check.
	 *
	 * Feature 127 registers this suite's abilities whether or not the plugin is active, so that
	 * on a site with no backup plugin the abilities are still discoverable and are the thing
	 * that answers "is this site backed up?" with no. A guard here broke exactly that: WP core
	 * silently drops every ability whose category is unregistered, so the whole suite vanished
	 * on the sites it was written for, leaving only a _doing_it_wrong notice per ability.
	 *
	 * Keep this in step with the registration gate in
	 * AcrossAI_Core_Abilities_Bootstrap::register_abilities(). A category costs one array entry
	 * and is never surfaced on its own, so erring towards registering it is the cheap side.
	 *
	 * @since  0.0.35
	 * @return void
	 */
	public function register(): void {
		wp_register_ability_category(
			'acrossai-updraftplus',
			array(
				'label'       => __( 'AcrossAI Abilities Manager — UpdraftPlus', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for UpdraftPlus: what backups exist, how recent they are, whether they are exposed, and taking or restoring one.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
