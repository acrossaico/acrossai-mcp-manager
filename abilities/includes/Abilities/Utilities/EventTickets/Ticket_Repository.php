<?php
/**
 * Feature 110 — tickets, capacity and providers, through Event Tickets' own API.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The Event Tickets boundary.
 *
 * Two things here are genuinely dangerous and shape the whole class.
 *
 * **Capacity is stock-aware.** `Tribe__Tickets__Tickets::update_capacity()` subtracts pending and
 * sold from the new stock on an update:
 *
 *     $data['stock'] -= $totals['pending'] + $totals['sold'];
 *
 * So writing `_tribe_ticket_capacity` and `_stock` directly — which the generic meta abilities will
 * happily do — double-counts every sale already made. On a paid event that is overselling or
 * underselling real inventory. Every capacity change here goes through `ticket_add()`, which calls
 * `update_capacity()` for us.
 *
 * **Capacity has four modes, not one number.** `own` keeps independent inventory, `global` draws
 * from the event's shared pool, `capped` draws from the pool up to a limit, and unlimited is
 * represented as capacity `-1` with an empty mode. Flattening that to an integer makes an unlimited
 * ticket and a sold-out one look identical, so the mode is always reported alongside the number.
 *
 * @since 0.0.34
 */
final class Ticket_Repository {

	/**
	 * Capacity meaning "no limit".
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	public const UNLIMITED = -1;

	/**
	 * The stock modes Event Tickets understands. Unlimited is stored as an empty string.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const MODES = array( 'own', 'global', 'capped', 'unlimited' );

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Post types that may carry tickets on this site.
	 *
	 * Defaults to events and pages; a site can add any type. Reading it rather than assuming
	 * `tribe_events` is what keeps this suite independent of The Events Calendar.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public static function ticketable_post_types(): array {
		if ( ! class_exists( 'Tribe__Tickets__Main' ) ) {
			return array();
		}

		$types = \Tribe__Tickets__Main::instance()->post_types();

		return array_values( array_map( 'strval', (array) $types ) );
	}

	/**
	 * The registered ticket providers, as rows.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function providers(): array {
		if ( ! class_exists( 'Tribe__Tickets__Tickets' ) ) {
			return array();
		}

		$rows = array();

		foreach ( (array) \Tribe__Tickets__Tickets::modules() as $class => $name ) {
			$class = (string) $class;
			$label = is_scalar( $name ) ? trim( (string) $name ) : '';

			// The registry value is null until the provider sets its name on `init`, and can stay
			// empty afterwards. Fall back to the instance, then to the class's last segment.
			if ( '' === $label ) {
				$label = self::provider_label( $class );
			}

			$rows[] = array(
				'provider' => $class,
				'label'    => $label,
				'paid'     => false !== stripos( $class, 'commerce' ) || false !== stripos( $class, 'woo' ) || false !== stripos( $class, 'edd' ),
			);
		}

		return $rows;
	}

	/**
	 * A readable name for a provider class.
	 *
	 * @since  0.0.34
	 * @param  string $class Provider class name.
	 * @return string
	 */
	private static function provider_label( string $class ): string {
		if ( class_exists( $class ) && method_exists( $class, 'get_instance' ) ) {
			$instance = call_user_func( array( $class, 'get_instance' ) );

			if ( is_object( $instance ) && ! empty( $instance->plugin_name ) ) {
				return (string) $instance->plugin_name;
			}
		}

		$parts = preg_split( '/__|\\\\/', $class );

		return (string) ( is_array( $parts ) ? end( $parts ) : $class );
	}

	/**
	 * Confirm a post exists and can carry tickets.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return WP_Post|WP_Error
	 */
	public static function require_ticketable( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'unknown_post',
				sprintf( /* translators: %d: post ID */ __( 'No post with ID %d.', 'acrossai-abilities-manager' ), $post_id )
			);
		}

		$allowed = self::ticketable_post_types();

		if ( ! in_array( (string) $post->post_type, $allowed, true ) ) {
			return new WP_Error(
				'post_type_not_ticketable',
				sprintf(
					/* translators: 1: post type, 2: comma-separated allowed types */
					__( 'Tickets cannot be attached to "%1$s" on this site. Enabled types: %2$s. Change that under Tickets > Settings.', 'acrossai-abilities-manager' ),
					(string) $post->post_type,
					implode( ', ', $allowed )
				)
			);
		}

		return $post;
	}

	/**
	 * Load one ticket object, whichever provider owns it.
	 *
	 * @since  0.0.34
	 * @param  int $ticket_id Ticket post ID.
	 * @return object|WP_Error
	 */
	public static function require_ticket( int $ticket_id ) {
		if ( ! function_exists( 'tribe_tickets_get_ticket_provider' ) ) {
			return new WP_Error( 'tickets_unavailable', __( 'Event Tickets is not available.', 'acrossai-abilities-manager' ) );
		}

		$provider = \tribe_tickets_get_ticket_provider( $ticket_id );

		if ( ! $provider || ! method_exists( $provider, 'get_ticket' ) ) {
			return new WP_Error(
				'unknown_ticket',
				sprintf(
					/* translators: %d: ticket ID */
					__( 'No ticket with ID %d, or its provider is no longer active. A ticket sold through a provider that has since been deactivated cannot be read.', 'acrossai-abilities-manager' ),
					$ticket_id
				)
			);
		}

		$event_id = self::event_id_for_ticket( $ticket_id, $provider );
		$ticket   = $provider->get_ticket( $event_id, $ticket_id );

		if ( ! is_object( $ticket ) ) {
			return new WP_Error(
				'unknown_ticket',
				sprintf( /* translators: %d: ticket ID */ __( 'No ticket with ID %d.', 'acrossai-abilities-manager' ), $ticket_id )
			);
		}

		return $ticket;
	}

	/**
	 * Which post a ticket belongs to.
	 *
	 * @since  0.0.34
	 * @param  int    $ticket_id Ticket ID.
	 * @param  object $provider  Provider instance.
	 * @return int
	 */
	public static function event_id_for_ticket( int $ticket_id, $provider ): int {
		if ( is_object( $provider ) && method_exists( $provider, 'get_event_id_from_attendee_id' ) ) {
			// Provider-specific relation meta; fall through to the generic keys when absent.
			$candidate = (int) get_post_meta( $ticket_id, '_tec_tickets_commerce_event', true );

			if ( $candidate ) {
				return $candidate;
			}
		}

		foreach ( array( '_tec_tickets_commerce_event', '_tribe_rsvp_for_event', '_tribe_tpp_for_event', '_tribe_wooticket_for_event' ) as $key ) {
			$candidate = (int) get_post_meta( $ticket_id, $key, true );

			if ( $candidate ) {
				return $candidate;
			}
		}

		return 0;
	}

	/**
	 * Every ticket on a post, across providers.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function tickets_for( int $post_id ): array {
		if ( ! class_exists( 'Tribe__Tickets__Tickets' ) ) {
			return array();
		}

		$rows = array();

		foreach ( (array) \Tribe__Tickets__Tickets::get_all_event_tickets( $post_id ) as $ticket ) {
			if ( is_object( $ticket ) ) {
				$rows[] = self::describe_ticket( $ticket, $post_id );
			}
		}

		return $rows;
	}

	/**
	 * Shape one ticket, capacity model intact.
	 *
	 * @since  0.0.34
	 * @param  object $ticket   Ticket object.
	 * @param  int    $event_id Owning post ID.
	 * @return array<string, mixed>
	 */
	public static function describe_ticket( $ticket, int $event_id = 0 ): array {
		$id       = (int) ( $ticket->ID ?? 0 );
		$capacity = self::capacity_of( $id, $ticket );
		$mode     = method_exists( $ticket, 'global_stock_mode' ) ? (string) $ticket->global_stock_mode() : '';

		return array(
			'id'             => $id,
			'event_id'       => $event_id ?: (int) get_post_meta( $id, '_tec_tickets_commerce_event', true ),
			'name'           => (string) ( $ticket->name ?? get_the_title( $id ) ),
			'description'    => (string) ( $ticket->description ?? '' ),
			'provider'       => isset( $ticket->provider_class ) ? (string) $ticket->provider_class : '',
			'price'          => isset( $ticket->price ) ? (string) $ticket->price : '',
			'start_date'     => (string) get_post_meta( $id, '_ticket_start_date', true ),
			'end_date'       => (string) get_post_meta( $id, '_ticket_end_date', true ),
			'capacity'       => $capacity,
			'unlimited'      => self::UNLIMITED === $capacity,
			'capacity_mode'  => '' === $mode ? 'unlimited' : $mode,
			'shared_cap'     => (int) get_post_meta( $id, '_global_stock_cap', true ),
			'stock'          => method_exists( $ticket, 'stock' ) ? (int) $ticket->stock() : 0,
			'sold'           => method_exists( $ticket, 'qty_sold' ) ? (int) $ticket->qty_sold() : 0,
			'pending'        => method_exists( $ticket, 'qty_pending' ) ? (int) $ticket->qty_pending() : 0,
			'available'      => method_exists( $ticket, 'available' ) ? (int) $ticket->available() : 0,
			'type'           => (string) get_post_meta( $id, '_type', true ),
		);
	}

	/**
	 * A ticket's capacity, with unlimited reported as -1.
	 *
	 * `Ticket_Object::capacity()` returns 0 for an unlimited ticket, not -1 — measured: a ticket
	 * whose stored `_tribe_ticket_capacity` was `-1` and `_manage_stock` was `no` read back as 0.
	 * Reporting that would make an unlimited ticket indistinguishable from a sold-out one, which is
	 * the exact confusion this suite exists to avoid, and it made a successful write look rejected.
	 *
	 * `tribe_tickets_get_capacity()` is the documented reader and normalises the stored empty value
	 * to -1; the object method is only a fallback.
	 *
	 * @since  0.0.34
	 * @param  int    $id     Ticket ID.
	 * @param  object $ticket Ticket object.
	 * @return int
	 */
	private static function capacity_of( int $id, $ticket ): int {
		if ( function_exists( 'tribe_tickets_get_capacity' ) ) {
			$capacity = \tribe_tickets_get_capacity( $id );

			if ( null !== $capacity && '' !== $capacity ) {
				return (int) $capacity;
			}
		}

		$stored = get_post_meta( $id, '_tribe_ticket_capacity', true );

		if ( '' !== (string) $stored ) {
			return (int) $stored;
		}

		return method_exists( $ticket, 'capacity' ) ? (int) $ticket->capacity() : self::UNLIMITED;
	}

	/**
	 * The capacity picture for a whole post.
	 *
	 * Reports the event-level shared pool as well as each ticket, because a ticket in `global` or
	 * `capped` mode has no meaningful capacity of its own — it draws from the pool, and reading its
	 * number alone tells a caller nothing about how many seats are really left.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function capacity_report( int $post_id ): array {
		$shared_enabled = (bool) get_post_meta( $post_id, '_tribe_ticket_use_global_stock', true );
		$shared_level   = get_post_meta( $post_id, '_tribe_ticket_global_stock_level', true );
		$event_capacity = get_post_meta( $post_id, '_tribe_ticket_capacity', true );

		$tickets = self::tickets_for( $post_id );
		$sold    = 0;
		$pending = 0;

		foreach ( $tickets as $ticket ) {
			$sold    += (int) $ticket['sold'];
			$pending += (int) $ticket['pending'];
		}

		return array(
			'post_id'              => $post_id,
			'shared_capacity_on'   => $shared_enabled,
			'shared_capacity'      => '' === (string) $event_capacity ? null : (int) $event_capacity,
			'shared_stock_left'    => '' === (string) $shared_level ? null : (int) $shared_level,
			'total_sold'           => $sold,
			'total_pending'        => $pending,
			'tickets'              => $tickets,
		);
	}

	/**
	 * Create or update a ticket through Event Tickets' own front door.
	 *
	 * `ticket_add()` is used rather than the ORM because `tribe_tickets()->create()` returns false
	 * by design — the default repository spans every provider and refuses to create when it cannot
	 * tell which one is meant — and because only `ticket_add()` runs `update_capacity()`, which is
	 * what keeps stock consistent with what has already been sold.
	 *
	 * @since  0.0.34
	 * @param  int                  $post_id Owning post.
	 * @param  array<string, mixed> $data    ticket_add() payload.
	 * @param  string               $provider_class Provider to use, or '' for the site default.
	 * @return int|WP_Error Ticket ID.
	 */
	public static function save_ticket( int $post_id, array $data, string $provider_class = '' ) {
		$provider = self::resolve_provider( $provider_class );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$ticket_id = $provider->ticket_add( $post_id, $data );

		if ( ! $ticket_id ) {
			return new WP_Error(
				'ticket_save_failed',
				__( 'Event Tickets refused the ticket and gave no reason. A ticket needs a name, and a paid ticket needs a price its provider accepts.', 'acrossai-abilities-manager' )
			);
		}

		return (int) $ticket_id;
	}

	/**
	 * Pick the provider to write through.
	 *
	 * @since  0.0.34
	 * @param  string $provider_class Requested provider class, or ''.
	 * @return object|WP_Error
	 */
	public static function resolve_provider( string $provider_class = '' ) {
		if ( ! class_exists( 'Tribe__Tickets__Tickets' ) ) {
			return new WP_Error( 'tickets_unavailable', __( 'Event Tickets is not available.', 'acrossai-abilities-manager' ) );
		}

		$modules = (array) \Tribe__Tickets__Tickets::modules();

		if ( '' !== $provider_class ) {
			/*
			 * array_key_exists, not isset. A provider registers itself in its constructor but sets
			 * its display name on `init` priority 9, so the registry legitimately holds
			 * [ 'Tribe__Tickets__RSVP' => null ] and isset() reports the active provider as absent.
			 * The symptom was an error that contradicted itself: "not an active provider. Active:
			 * <that same provider>".
			 */
			if ( ! array_key_exists( $provider_class, $modules ) ) {
				return new WP_Error(
					'unknown_provider',
					sprintf(
						/* translators: 1: requested provider, 2: comma-separated active providers */
						__( '"%1$s" is not an active ticket provider on this site. Active: %2$s.', 'acrossai-abilities-manager' ),
						$provider_class,
						implode( ', ', array_keys( $modules ) )
					)
				);
			}

			$instance = call_user_func( array( $provider_class, 'get_instance' ) );

			return is_object( $instance ) ? $instance : new WP_Error( 'unknown_provider', __( 'That provider could not be loaded.', 'acrossai-abilities-manager' ) );
		}

		$default = \Tribe__Tickets__Tickets::get_default_module();

		if ( ! $default || ! class_exists( $default ) ) {
			return new WP_Error( 'no_provider', __( 'No ticket provider is active. Enable RSVP or Tickets Commerce first.', 'acrossai-abilities-manager' ) );
		}

		$instance = call_user_func( array( $default, 'get_instance' ) );

		return is_object( $instance ) ? $instance : new WP_Error( 'no_provider', __( 'The default ticket provider could not be loaded.', 'acrossai-abilities-manager' ) );
	}

	/**
	 * Ticketing settings, credentials excluded by construction.
	 *
	 * Only keys from the shared settings blob are returned, and only the ticketing ones. Gateway
	 * credentials — Stripe, PayPal and Square access tokens, webhook signing keys, PKCE verifiers —
	 * are NOT in that blob; Tickets Commerce stores them as standalone option rows precisely so they
	 * are not swept up by settings readers. This never reads those rows, so there is no denylist to
	 * keep in sync for them. The pattern match below is a second line of defence for anything
	 * credential-shaped that ends up in the blob later.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function settings(): array {
		$options = (array) get_option( 'tribe_events_calendar_options', array() );
		$rows    = array();

		foreach ( $options as $key => $value ) {
			$key = (string) $key;

			if ( ! self::is_ticket_setting( $key ) ) {
				continue;
			}

			$secret = self::is_secret( $key );

			$rows[] = array(
				'key'      => $key,
				'value'    => $secret ? null : ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ),
				'redacted' => $secret,
			);
		}

		return $rows;
	}

	/**
	 * Whether a shared-blob key belongs to ticketing.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return bool
	 */
	private static function is_ticket_setting( string $key ): bool {
		foreach ( array( 'ticket', 'tickets-commerce', 'rsvp', 'attendee' ) as $marker ) {
			if ( false !== stripos( $key, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a settings key looks like a credential.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return bool
	 */
	public static function is_secret( string $key ): bool {
		return 1 === preg_match(
			'/(_key|_token|_secret|secret|password|signing|verifier|signup_data|access_token|client_id|merchant)/i',
			$key
		);
	}

	/**
	 * What has been sold, in aggregate.
	 *
	 * No identifying data: totals per ticket and per status only.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID, or 0 for the whole site.
	 * @return array<string, mixed>
	 */
	public static function sales_summary( int $post_id = 0 ): array {
		$tickets = 0 !== $post_id ? self::tickets_for( $post_id ) : array();
		$sold    = 0;
		$pending = 0;
		$revenue = 0.0;
		$rows    = array();

		foreach ( $tickets as $ticket ) {
			$sold    += (int) $ticket['sold'];
			$pending += (int) $ticket['pending'];
			$revenue += (float) $ticket['price'] * (int) $ticket['sold'];

			$rows[] = array(
				'ticket_id' => $ticket['id'],
				'name'      => $ticket['name'],
				'price'     => $ticket['price'],
				'sold'      => $ticket['sold'],
				'pending'   => $ticket['pending'],
			);
		}

		return array(
			'post_id'          => $post_id,
			'tickets_sold'     => $sold,
			'tickets_pending'  => $pending,
			'estimated_revenue' => round( $revenue, 2 ),
			'per_ticket'       => $rows,
		);
	}

	/**
	 * Delete a ticket.
	 *
	 * Event Tickets removes a ticket permanently — there is no trash for these — and stamps the
	 * name onto any attendee who bought one so the record is not orphaned. That is the plugin's own
	 * behaviour and is why the ability confirm-gates.
	 *
	 * @since  0.0.34
	 * @param  int $ticket_id Ticket ID.
	 * @return true|WP_Error
	 */
	public static function delete_ticket( int $ticket_id ) {
		$provider = function_exists( 'tribe_tickets_get_ticket_provider' ) ? \tribe_tickets_get_ticket_provider( $ticket_id ) : false;

		if ( ! $provider || ! method_exists( $provider, 'delete_ticket' ) ) {
			return new WP_Error( 'unknown_ticket', __( 'That ticket has no active provider, so it cannot be deleted through the plugin.', 'acrossai-abilities-manager' ) );
		}

		$event_id = self::event_id_for_ticket( $ticket_id, $provider );
		$deleted  = $provider->delete_ticket( $event_id, $ticket_id );

		if ( ! $deleted ) {
			return new WP_Error( 'ticket_delete_failed', __( 'Event Tickets refused to delete that ticket.', 'acrossai-abilities-manager' ) );
		}

		return true;
	}
}
