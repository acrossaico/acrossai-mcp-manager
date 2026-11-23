<?php
/**
 * Feature 109 — registers the ability category used by the Classic Editor suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventsCalendar
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventsCalendar;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventsCalendar\Events_Calendar_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-events-calendar` on wp_abilities_api_categories_init.
 *
 * WP core silently drops any ability whose category was not pre-registered
 * (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION), so this must keep running before the Library
 * Processor calls wp_register_ability() at wp_abilities_api_init P5.
 */
final class Category_Registrar {

	/**
	 * @since 0.0.34
	 * @var   self|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * @since  0.0.34
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @since  0.0.34
	 * @return void
	 */
	public function register(): void {
		if ( ! Events_Calendar_Guard::is_available() ) {
			return;
		}

		wp_register_ability_category(
			'acrossai-events-calendar',
			array(
				'label'       => __( 'Acrossai Abilities Manager — The Events Calendar', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for The Events Calendar: events, venues, organizers and event categories, read and written through the calendar\'s own data layer so an event\'s dates stay consistent across post meta and its custom tables.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
