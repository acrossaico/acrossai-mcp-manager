<?php
/**
 * Feature 110 — attendees, check-in and orders, with a deliberate stance on personal data.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\EventTickets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventTickets;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Attendee and order reads.
 *
 * Attendee records are personal data — Event Tickets registers GDPR exporters and erasers for them,
 * and its own export includes full name, email and purchase date. An attendee list handed to an AI
 * client leaves the site, may be retained by whatever model provider is on the other end, and
 * cannot be recalled. So:
 *
 *   - The default answer is aggregate. Counts, check-in totals and per-ticket breakdowns carry no
 *     identifying data at all, and that is enough for most questions an organiser actually asks.
 *   - Names and emails are returned only when the caller passes `include_personal_data: true`, and
 *     the response says how many records it disclosed so the disclosure is visible in the log.
 *   - Listing is always paginated. The reference implementation for this plugin returns every
 *     attendee of an event in one unpaginated response; a single call there dumps the lot.
 *   - `security_code` is NEVER returned, under any flag. It is the check-in credential printed on
 *     the ticket — disclosing it lets someone check in as another attendee. There is no legitimate
 *     reason for an assistant to hold it, so it is not exposed rather than merely gated.
 *   - Payment-gateway meta is never returned either. Those fields carry processor identifiers and
 *     raw gateway payloads.
 *
 * @since 0.0.34
 */
final class Attendee_Repository {

	/**
	 * Fields never returned regardless of flags.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const NEVER_RETURNED = array(
		'security_code',
		'security',
		'qr_ticket_id',
	);

	/**
	 * Maximum attendees per page, whatever the caller asks for.
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Aggregate attendance for a post, with no identifying data.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function summary( int $post_id ): array {
		$total      = 0;
		$checked_in = 0;

		if ( class_exists( 'Tribe__Tickets__Tickets' ) ) {
			$total      = (int) \Tribe__Tickets__Tickets::get_event_attendees_count( $post_id );
			$checked_in = (int) \Tribe__Tickets__Tickets::get_event_checkedin_attendees_count( $post_id );
		}

		$per_ticket = array();

		foreach ( Ticket_Repository::tickets_for( $post_id ) as $ticket ) {
			$per_ticket[] = array(
				'ticket_id' => $ticket['id'],
				'name'      => $ticket['name'],
				'sold'      => $ticket['sold'],
				'pending'   => $ticket['pending'],
				'available' => $ticket['available'],
				'capacity'  => $ticket['capacity'],
				'unlimited' => $ticket['unlimited'],
			);
		}

		return array(
			'post_id'         => $post_id,
			'attendees'       => $total,
			'checked_in'      => $checked_in,
			'not_checked_in'  => max( 0, $total - $checked_in ),
			'per_ticket'      => $per_ticket,
		);
	}

	/**
	 * Attendees for a post, paginated, identifying fields opt-in.
	 *
	 * @since  0.0.34
	 * @param  int  $post_id  Post ID.
	 * @param  bool $with_pii Whether to include names and emails.
	 * @param  int  $page     1-based page.
	 * @param  int  $per_page Page size, capped.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, disclosed: int}
	 */
	public static function attendees( int $post_id, bool $with_pii, int $page, int $per_page ): array {
		if ( ! class_exists( 'Tribe__Tickets__Tickets' ) ) {
			return array( 'rows' => array(), 'total' => 0, 'disclosed' => 0 );
		}

		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = max( 1, $page );

		$all   = (array) \Tribe__Tickets__Tickets::get_event_attendees( $post_id );
		$total = count( $all );
		$slice = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );

		$rows      = array();
		$disclosed = 0;

		foreach ( $slice as $attendee ) {
			$attendee = (array) $attendee;

			/*
			 * get_event_attendees() does not guarantee a decorated record. Measured: it returned
			 * plain post arrays keyed ID/post_author/post_date with none of the attendee_id,
			 * product_id or holder_name fields the decorated shape carries. Reading only the
			 * decorated keys produced empty rows. Take the ID from either shape and read the rest
			 * from the provider meta, which is the same data the decoration is built from.
			 */
			$id = (int) ( $attendee['attendee_id'] ?? $attendee['ID'] ?? 0 );

			if ( ! $id ) {
				continue;
			}

			$row = array(
				'attendee_id' => $id,
				'ticket_id'   => (int) ( $attendee['product_id'] ?? self::meta_any( $id, array( '_tec_tickets_commerce_ticket', '_tribe_rsvp_product', '_tribe_tpp_product' ) ) ),
				'ticket_name' => (string) ( $attendee['ticket_name'] ?? $attendee['ticket'] ?? '' ),
				'order_id'    => (int) ( $attendee['order_id'] ?? self::meta_any( $id, array( '_tec_tickets_commerce_order', '_tribe_rsvp_order', '_tribe_tpp_order' ) ) ),
				'checked_in'  => isset( $attendee['check_in'] ) ? ! empty( $attendee['check_in'] ) : self::is_checked_in( $id ),
				'provider'    => (string) ( $attendee['provider_slug'] ?? $attendee['provider'] ?? '' ),
			);

			if ( '' === $row['ticket_name'] && $row['ticket_id'] ) {
				$row['ticket_name'] = (string) get_the_title( $row['ticket_id'] );
			}

			if ( $with_pii ) {
				$row['name']  = (string) ( $attendee['holder_name'] ?? $attendee['purchaser_name'] ?? self::meta_any( $id, array( '_tec_tickets_commerce_full_name', '_tribe_rsvp_full_name', '_tribe_tpp_full_name', '_tribe_tickets_full_name' ) ) );
				$row['email'] = (string) ( $attendee['holder_email'] ?? $attendee['purchaser_email'] ?? self::meta_any( $id, array( '_tec_tickets_commerce_email', '_tribe_rsvp_email', '_tribe_tpp_email', '_tribe_tickets_email' ) ) );
				++$disclosed;
			}

			$rows[] = $row;
		}

		return array( 'rows' => $rows, 'total' => $total, 'disclosed' => $disclosed );
	}

	/**
	 * First non-empty value among several provider-specific meta keys.
	 *
	 * Every field on an attendee has a different key per provider, so a reader that wants to work
	 * across RSVP, Tickets Commerce and the legacy provider has to try each. Deliberately does NOT
	 * include any security-code key — that field is never returned.
	 *
	 * @since  0.0.34
	 * @param  int      $id   Attendee ID.
	 * @param  string[] $keys Candidate meta keys, in priority order.
	 * @return string
	 */
	private static function meta_any( int $id, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = get_post_meta( $id, $key, true );

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Check an attendee in or out.
	 *
	 * @since  0.0.34
	 * @param  int  $attendee_id Attendee ID.
	 * @param  bool $in          True to check in, false to undo.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_check_in( int $attendee_id, bool $in ) {
		if ( ! function_exists( 'tribe_tickets_get_ticket_provider' ) ) {
			return new WP_Error( 'tickets_unavailable', __( 'Event Tickets is not available.', 'acrossai-abilities-manager' ) );
		}

		$provider = \tribe_tickets_get_ticket_provider( $attendee_id );

		if ( ! $provider || ! method_exists( $provider, 'checkin' ) ) {
			return new WP_Error(
				'unknown_attendee',
				sprintf(
					/* translators: %d: attendee ID */
					__( 'No attendee with ID %d, or its ticket provider is no longer active.', 'acrossai-abilities-manager' ),
					$attendee_id
				)
			);
		}

		if ( $in ) {
			$provider->checkin( $attendee_id, false );
		} else {
			$provider->uncheckin( $attendee_id );
		}

		// Read the state back: check-in is filterable, and a site can veto it.
		$stored = self::is_checked_in( $attendee_id );

		if ( $stored !== $in ) {
			return new WP_Error(
				'check_in_rejected',
				sprintf(
					/* translators: 1: attendee ID, 2: requested state */
					__( 'Attendee %1$d is still %2$s. Event Tickets exposes a filter over check-in and something on this site vetoed the change.', 'acrossai-abilities-manager' ),
					$attendee_id,
					$in ? __( 'not checked in', 'acrossai-abilities-manager' ) : __( 'checked in', 'acrossai-abilities-manager' )
				)
			);
		}

		return array(
			'attendee_id' => $attendee_id,
			'checked_in'  => $stored,
		);
	}

	/**
	 * Current check-in state, across providers.
	 *
	 * @since  0.0.34
	 * @param  int $attendee_id Attendee ID.
	 * @return bool
	 */
	public static function is_checked_in( int $attendee_id ): bool {
		foreach ( array( '_tec_tickets_commerce_checked_in', '_tribe_rsvp_checkedin', '_tribe_tpp_checkedin' ) as $key ) {
			if ( '' !== (string) get_post_meta( $attendee_id, $key, true ) ) {
				return (bool) get_post_meta( $attendee_id, $key, true );
			}
		}

		return false;
	}

	/**
	 * One order.
	 *
	 * @since  0.0.34
	 * @param  int  $order_id Order ID.
	 * @param  bool $with_pii Whether to include purchaser name and email.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function order( int $order_id, bool $with_pii ) {
		$post = get_post( $order_id );

		if ( ! $post || 'tec_tc_order' !== $post->post_type ) {
			return new WP_Error(
				'unknown_order',
				sprintf( /* translators: %d: order ID */ __( 'No order with ID %d.', 'acrossai-abilities-manager' ), $order_id )
			);
		}

		$row = array(
			'order_id' => $order_id,
			'status'   => (string) $post->post_status,
			'total'    => (string) get_post_meta( $order_id, '_tec_tc_order_total_value', true ),
			'subtotal' => (string) get_post_meta( $order_id, '_tec_tc_order_subtotal_value', true ),
			'currency' => (string) get_post_meta( $order_id, '_tec_tc_order_currency', true ),
			'gateway'  => (string) get_post_meta( $order_id, '_tec_tc_order_gateway', true ),
			'date'     => (string) $post->post_date,
		);

		if ( $with_pii ) {
			$row['purchaser_name']  = (string) get_post_meta( $order_id, '_tec_tc_order_purchaser_full_name', true );
			$row['purchaser_email'] = (string) get_post_meta( $order_id, '_tec_tc_order_purchaser_email', true );
		}

		return $row;
	}

	/**
	 * Every Tickets Commerce order status, as WordPress post-status slugs.
	 *
	 * Read from the plugin's own status handler rather than hardcoded, so a status added upstream
	 * is included automatically. `trash` is excluded — a trashed order is not part of the ledger.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	private static function order_statuses(): array {
		if ( ! function_exists( 'tribe' ) || ! class_exists( '\\TEC\\Tickets\\Commerce\\Status\\Status_Handler' ) ) {
			return array();
		}

		$handler = \tribe( \TEC\Tickets\Commerce\Status\Status_Handler::class );

		if ( ! is_object( $handler ) || ! method_exists( $handler, 'get_all' ) ) {
			return array();
		}

		$slugs = array();

		foreach ( (array) $handler->get_all() as $status ) {
			if ( ! is_object( $status ) || ! method_exists( $status, 'get_wp_slug' ) ) {
				continue;
			}

			$slug = (string) $status->get_wp_slug();

			if ( '' !== $slug && 'trash' !== $status::SLUG ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Orders, paginated, identifying fields opt-in.
	 *
	 * Gateway meta — processor identifiers, raw payloads, customer references — is never included.
	 * Money totals and status are, because that is what an operator is asking about.
	 *
	 * @since  0.0.34
	 * @param  bool $with_pii Whether to include purchaser name and email.
	 * @param  int  $page     1-based page.
	 * @param  int  $per_page Page size, capped.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, disclosed: int}
	 */
	public static function orders( bool $with_pii, int $page, int $per_page ): array {
		if ( ! function_exists( 'tec_tc_orders' ) ) {
			return array( 'rows' => array(), 'total' => 0, 'disclosed' => 0 );
		}

		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = max( 1, $page );

		$orm = \tec_tc_orders();

		/*
		 * The order repository defaults post_status to a SINGLE status — whichever one Tickets
		 * Commerce inserts new orders in. Leaving that default in place means "list the orders"
		 * silently returns only the ones in that state and omits every completed, refunded or
		 * denied order, which are the ones an operator is actually asking about. Ask for all of
		 * them explicitly.
		 */
		$statuses = self::order_statuses();

		if ( array() !== $statuses ) {
			$orm->by( 'status', $statuses );
		}

		$total = (int) $orm->found();

		$orm->page( $page )->per_page( $per_page );

		$rows      = array();
		$disclosed = 0;

		foreach ( (array) $orm->all() as $order ) {
			if ( ! is_object( $order ) || ! isset( $order->ID ) ) {
				continue;
			}

			$id  = (int) $order->ID;
			$row = array(
				'order_id' => $id,
				'status'   => (string) ( $order->post_status ?? '' ),
				'total'    => (string) get_post_meta( $id, '_tec_tc_order_total_value', true ),
				'currency' => (string) get_post_meta( $id, '_tec_tc_order_currency', true ),
				'gateway'  => (string) get_post_meta( $id, '_tec_tc_order_gateway', true ),
				'date'     => (string) ( $order->post_date ?? '' ),
			);

			if ( $with_pii ) {
				$row['purchaser_name']  = (string) get_post_meta( $id, '_tec_tc_order_purchaser_full_name', true );
				$row['purchaser_email'] = (string) get_post_meta( $id, '_tec_tc_order_purchaser_email', true );
				++$disclosed;
			}

			$rows[] = $row;
		}

		return array( 'rows' => $rows, 'total' => $total, 'disclosed' => $disclosed );
	}
}
