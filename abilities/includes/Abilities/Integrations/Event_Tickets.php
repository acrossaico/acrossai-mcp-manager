<?php
/**
 * Event Tickets' toolset declaration.
 *
 * Separate from The Events Calendar on purpose. Event Tickets has no dependency on it — tickets
 * attach to any post type in `ticket-enabled-post-types`, which defaults to events AND pages — so a
 * site can run one without the other and each tab must appear on its own terms.
 *
 * Event Tickets registers no abilities of its own, so `ability_prefixes()` is empty.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's Event Tickets abilities.
 */
final class Event_Tickets implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Event_Tickets_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'event-tickets';

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
		return __( 'Event Tickets', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Event Tickets: read and manage the tickets on a post, see the real capacity picture including shared pools and unlimited tickets, check attendance and check people in, and read orders and sales totals. Tickets attach to any post type enabled for them, which is events and pages by default, so this does not require The Events Calendar. Capacity changes go through the plugin\'s own save path because it reconciles stock against what has already been sold — setting the capacity meta directly double-counts existing sales. Attendee and purchaser details are aggregate by default: names and emails are returned only when explicitly asked for, results are paginated, and the check-in security code is never returned at all. Requires administrator rights. Only present when Event Tickets is active. Narrow action=discover with sub_group: tickets, capacity, attendees, orders, setup. action=info returns schemas; action=execute runs one ability.',
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
		return class_exists( 'Tribe__Tickets__Main' ) && class_exists( 'Tribe__Tickets__Tickets' );
	}
}
