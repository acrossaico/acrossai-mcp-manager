<?php
/**
 * Feature 110 — List Ticket Providers.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventTickets;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets\Ticket_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets\Event_Tickets_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * tickets/list-ticket-providers — List Ticket Providers.
 */
final class List_Ticket_Providers extends Base_Event_Tickets_Ability {

	protected function slug(): string {
		return 'tickets/list-ticket-providers';
	}

	protected function ability_label(): string {
		return __( 'List Ticket Providers', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Which ticket providers are active, which post types can carry tickets, and whether Tickets Commerce and the Plus add-on are available. Check this before creating a ticket: RSVP is always present but paid tickets need Tickets Commerce switched on, and the WooCommerce and EDD providers need Event Tickets Plus.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'setup';
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array(  );
	}

	protected function output_properties(): array {
		return array(
			'providers' => array( 'type' => 'array' ),
			'ticketable_post_types' => array( 'type' => 'array' ),
			'commerce_enabled' => array( 'type' => 'boolean' ),
			'plus_active' => array( 'type' => 'boolean' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$providers  = Ticket_Repository::providers();
		$ticketable = Ticket_Repository::ticketable_post_types();

		return array(
			'providers'             => $providers,
			'ticketable_post_types' => $ticketable,
			'commerce_enabled'      => Event_Tickets_Guard::commerce_enabled(),
			'plus_active'           => Event_Tickets_Guard::plus_active(),
			'message'               => sprintf(
				/* translators: 1: number of providers, 2: comma-separated post types */
				__( '%1$d provider(s). Tickets can be attached to: %2$s.', 'acrossai-abilities-manager' ),
				count( $providers ),
				implode( ', ', $ticketable )
			),
		);
	}
}
