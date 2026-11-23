<?php
/**
 * The Events Calendar's toolset declaration.
 *
 * The "we supply everything" shape: The Events Calendar registers no abilities of its own — verified
 * across the plugin including its bundled common library — so every ability in this group is one of
 * ours. `ability_prefixes()` is empty for that reason; claiming `events` would capture any future
 * ability in that namespace.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's Events Calendar abilities.
 */
final class Events_Calendar implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Events_Calendar_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'events-calendar';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'The Events Calendar', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'The Events Calendar: find events by date range, venue, organizer, category or cost; read one event with its dates, timezone, venue and organizers resolved; create, reschedule and trash events; manage venues and organizers; and read the calendar settings. Use this rather than the Content tool for anything on an event — an event\'s timing lives in post meta and in the calendar\'s own tables at the same time, and writing the meta directly moves one and not the others, which leaves the event showing different times in the calendar and on its own page. Requires administrator rights. Recurring events are refused: recurrence is a Pro feature and a series cannot be edited safely from here. Only present when The Events Calendar is active. Narrow action=discover with sub_group: events, venues, organizers, categories, calendar. action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Claims no prefixes — every ability in this group is declared by us.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( 'Tribe__Events__Main' ) && function_exists( 'tribe_events' );
	}
}
