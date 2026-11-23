<?php
/**
 * Feature 109 — every Events Calendar read and write goes through here.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\EventsCalendar
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventsCalendar;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The ORM boundary for events, venues and organizers.
 *
 * Nothing in this suite writes event meta directly, and the reason is measurable. An event's timing
 * is stored in four places: the `_Event*` post meta, the `tec_events` custom table, the
 * `tec_occurrences` custom table, and the derived meta the ORM computes (`_EventStartDateUTC`,
 * `_EventDuration`, `_EventTimezoneAbbr`). Writing `_EventStartDate` with `update_post_meta()` moves
 * exactly one of them. Measured on a live install: after such a write the meta said 14:00, the UTC
 * twin still said 14:00 UTC — which is the OLD local time for that timezone — and both custom tables
 * still said 09:00. Calendar views read the tables, the single-event template reads the meta, and
 * nothing reports an error. The ORM is the only thing that keeps all four in step.
 *
 * Three ORM behaviours are load-bearing here and are handled explicitly:
 *
 *   1. `create()` returns `WP_Post|false` — never a `WP_Error`, and with no reason attached. Callers
 *      get a generic failure, so this class checks its own preconditions first to give a better one.
 *   2. `save()` writes to EVERY post matching the current query. It must be scoped by id first, or
 *      an update becomes a mass update.
 *   3. If the resulting end date precedes the start date, the ORM silently discards the whole date
 *      block and still reports success. Writes are therefore read back and compared.
 *
 * @since 0.0.34
 */
final class Event_Repository {

	/**
	 * Recurrence meta. Rules only exist with Events Calendar Pro; free TEC displays them and never
	 * edits occurrences, so this suite refuses rather than half-handling a series.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const RECURRENCE_META = '_EventRecurrence';

	/**
	 * Meta the ORM derives. Writing any of these directly desynchronises the event.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const DERIVED_META = array(
		'_EventStartDateUTC',
		'_EventEndDateUTC',
		'_EventDuration',
		'_EventTimezoneAbbr',
		'_EventOrigin',
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Post type names, read from the plugin's constants rather than hardcoded.
	 *
	 * @since  0.0.34
	 * @param  string $which event|venue|organizer.
	 * @return string
	 */
	public static function post_type( string $which ): string {
		if ( ! class_exists( 'Tribe__Events__Main' ) ) {
			return '';
		}

		switch ( $which ) {
			case 'venue':
				return (string) \Tribe__Events__Main::VENUE_POST_TYPE;

			case 'organizer':
				return (string) \Tribe__Events__Main::ORGANIZER_POST_TYPE;

			case 'category':
				return (string) \Tribe__Events__Main::TAXONOMY;

			default:
				return (string) \Tribe__Events__Main::POSTTYPE;
		}
	}

	/**
	 * The repository for one object type.
	 *
	 * @since  0.0.34
	 * @param  string $which event|venue|organizer.
	 * @return object|null
	 */
	private static function orm( string $which ) {
		switch ( $which ) {
			case 'venue':
				return function_exists( 'tribe_venues' ) ? \tribe_venues() : null;

			case 'organizer':
				return function_exists( 'tribe_organizers' ) ? \tribe_organizers() : null;

			default:
				return function_exists( 'tribe_events' ) ? \tribe_events() : null;
		}
	}

	/**
	 * Whether a post is a recurring event this suite must not touch.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return bool
	 */
	public static function is_recurring( int $post_id ): bool {
		$recurrence = get_post_meta( $post_id, self::RECURRENCE_META, true );

		return is_array( $recurrence ) && ! empty( $recurrence['rules'] );
	}

	/**
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function assert_not_recurring( int $post_id ) {
		if ( ! self::is_recurring( $post_id ) ) {
			return true;
		}

		return new WP_Error(
			'recurring_event',
			sprintf(
				/* translators: %d: post ID */
				__( 'Event %d is a recurring event. Recurrence is an Events Calendar Pro feature and this suite cannot edit a series safely — a write here would change the parent event without touching its occurrences. Edit it in the calendar UI instead.', 'acrossai-abilities-manager' ),
				$post_id
			)
		);
	}

	/**
	 * Confirm a post exists and is of the expected type.
	 *
	 * @since  0.0.34
	 * @param  int    $post_id Post ID.
	 * @param  string $which   event|venue|organizer.
	 * @return WP_Post|WP_Error
	 */
	public static function require_post( int $post_id, string $which ) {
		$post = get_post( $post_id );
		$type = self::post_type( $which );

		if ( ! $post instanceof WP_Post || $post->post_type !== $type ) {
			return new WP_Error(
				'unknown_' . $which,
				sprintf(
					/* translators: 1: post ID, 2: expected post type */
					__( 'No %2$s with ID %1$d.', 'acrossai-abilities-manager' ),
					$post_id,
					$which
				)
			);
		}

		return $post;
	}

	/**
	 * Create an object through the ORM.
	 *
	 * @since  0.0.34
	 * @param  string               $which event|venue|organizer.
	 * @param  array<string, mixed> $args  ORM aliases, already validated by the caller.
	 * @return WP_Post|WP_Error
	 */
	public static function create( string $which, array $args ) {
		$orm = self::orm( $which );

		if ( null === $orm ) {
			return new WP_Error( 'orm_unavailable', __( 'The Events Calendar ORM is not available.', 'acrossai-abilities-manager' ) );
		}

		$created = $orm->set_args( $args )->create();

		if ( ! $created instanceof WP_Post ) {
			// The ORM returns a bare false with no reason. Say what it most likely was.
			return new WP_Error(
				'create_failed',
				sprintf(
					/* translators: %s: object type */
					__( 'The Events Calendar refused to create the %s and gave no reason. An event needs at least a title and an end date (or a start date plus a duration); a venue or organizer needs a title.', 'acrossai-abilities-manager' ),
					$which
				)
			);
		}

		return $created;
	}

	/**
	 * Update one object through the ORM, scoped to a single ID.
	 *
	 * @since  0.0.34
	 * @param  string               $which   event|venue|organizer.
	 * @param  int                  $post_id Post ID.
	 * @param  array<string, mixed> $args    ORM aliases.
	 * @return true|WP_Error
	 */
	public static function update( string $which, int $post_id, array $args ) {
		$orm = self::orm( $which );

		if ( null === $orm ) {
			return new WP_Error( 'orm_unavailable', __( 'The Events Calendar ORM is not available.', 'acrossai-abilities-manager' ) );
		}

		// Scope FIRST. save() writes to every post the query matches, so an unscoped call is a
		// mass update of the whole calendar.
		$result = $orm->by_args(
			array(
				'id'     => $post_id,
				'status' => 'any',
			)
		)->set_args( $args )->save();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/*
		 * Do NOT treat anything else as failure. `save()` is documented to return
		 * [ id => true|WP_Error ], and on a single-event update it returns a Tribe__Promise
		 * instead — measured on a live install, for a write that had already been applied
		 * synchronously. Reading success out of that return is how an integration ends up
		 * reporting failure for writes that worked.
		 *
		 * Only an explicit error is an error. Correctness is established by reading the value back
		 * (assert_dates_applied), which is true regardless of which path the ORM took and does not
		 * require mutating a global filter to force the synchronous branch.
		 */
		if ( is_array( $result ) && isset( $result[ $post_id ] ) && is_wp_error( $result[ $post_id ] ) ) {
			return $result[ $post_id ];
		}

		return true;
	}

	/**
	 * Reject venue and organizer IDs that do not name a real venue or organizer.
	 *
	 * The ORM discards a relation it cannot resolve, silently, and still reports the save as fine.
	 * Checking up front turns "the venue quietly did not attach" into a named error.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $args ORM aliases.
	 * @return true|WP_Error
	 */
	public static function validate_links( array $args ) {
		if ( isset( $args['venue'] ) && $args['venue'] ) {
			$venue = self::require_post( (int) $args['venue'], 'venue' );

			if ( is_wp_error( $venue ) ) {
				return new WP_Error(
					'unknown_venue',
					sprintf(
						/* translators: %d: supplied ID */
						__( '%d is not a venue. The calendar would have discarded the link without reporting it.', 'acrossai-abilities-manager' ),
						(int) $args['venue']
					)
				);
			}
		}

		foreach ( (array) ( $args['organizers'] ?? array() ) as $organizer_id ) {
			$organizer = self::require_post( (int) $organizer_id, 'organizer' );

			if ( is_wp_error( $organizer ) ) {
				return new WP_Error(
					'unknown_organizer',
					sprintf(
						/* translators: %d: supplied ID */
						__( '%d is not an organizer. The calendar would have discarded the link without reporting it.', 'acrossai-abilities-manager' ),
						(int) $organizer_id
					)
				);
			}
		}

		return true;
	}

	/**
	 * Confirm the dates the caller asked for are the dates now stored.
	 *
	 * The ORM drops the entire date block, silently and without error, when the end date precedes
	 * the start date or when a date fails to parse. Without this check the ability reports a
	 * successful reschedule that never happened.
	 *
	 * @since  0.0.34
	 * @param  int                  $post_id Post ID.
	 * @param  array<string, mixed> $args    Args as submitted.
	 * @return true|WP_Error
	 */
	public static function assert_dates_applied( int $post_id, array $args ) {
		$map = array(
			'start_date' => '_EventStartDate',
			'end_date'   => '_EventEndDate',
		);

		foreach ( $map as $alias => $meta_key ) {
			if ( ! isset( $args[ $alias ] ) ) {
				continue;
			}

			$wanted = strtotime( (string) $args[ $alias ] );
			$stored = strtotime( (string) get_post_meta( $post_id, $meta_key, true ) );

			if ( false === $wanted || false === $stored || abs( $wanted - $stored ) > 60 ) {
				return new WP_Error(
					'dates_rejected',
					sprintf(
						/* translators: 1: requested value, 2: value actually stored */
						__( 'The Events Calendar did not apply the dates: asked for %1$s, the event still holds %2$s. It discards the whole date block without an error when the end precedes the start or a value cannot be parsed. Every other field in this call was saved.', 'acrossai-abilities-manager' ),
						wp_json_encode( $args[ $alias ] ),
						wp_json_encode( get_post_meta( $post_id, $meta_key, true ) )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Query events through the ORM.
	 *
	 * The date filters are the reason this exists. TEC's repository understands `starts_after`,
	 * `ends_before`, `runs_between` and friends against its own tables; a generic post query cannot
	 * express "what is on next week" at all, because the dates it would have to compare live in
	 * meta as strings.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $filters Already-validated filters.
	 * @param  int                  $page    1-based page.
	 * @param  int                  $per_page Page size.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query_events( array $filters, int $page, int $per_page ): array {
		$orm = self::orm( 'event' );

		if ( null === $orm ) {
			return array( 'rows' => array(), 'total' => 0 );
		}

		foreach ( $filters as $key => $value ) {
			$orm->where( $key, $value );
		}

		$total = (int) $orm->found();

		$orm->page( max( 1, $page ) )->per_page( $per_page );

		$rows = array();

		foreach ( (array) $orm->all() as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$row = self::describe_event( (int) $post->ID );

			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * List venues or organizers.
	 *
	 * @since  0.0.34
	 * @param  string $which    venue|organizer.
	 * @param  string $search   Optional title search.
	 * @param  int    $page     1-based page.
	 * @param  int    $per_page Page size.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query_linked( string $which, string $search, int $page, int $per_page ): array {
		$orm = self::orm( $which );

		if ( null === $orm ) {
			return array( 'rows' => array(), 'total' => 0 );
		}

		if ( '' !== $search ) {
			$orm->where( 'search', $search );
		}

		$total = (int) $orm->found();

		$orm->page( max( 1, $page ) )->per_page( $per_page );

		$rows = array();

		foreach ( (array) $orm->all() as $post ) {
			if ( $post instanceof WP_Post ) {
				$rows[] = self::describe_linked( (int) $post->ID, $which );
			}
		}

		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * How many events point at a venue or organizer.
	 *
	 * Reported before trashing one, because the calendar leaves the events behind pointing at a
	 * post that no longer exists rather than clearing the link.
	 *
	 * @since  0.0.34
	 * @param  int    $post_id Venue or organizer ID.
	 * @param  string $which   venue|organizer.
	 * @return int
	 */
	public static function linked_event_count( int $post_id, string $which ): int {
		$orm = self::orm( 'event' );

		if ( null === $orm ) {
			return 0;
		}

		return (int) $orm->where( $which, $post_id )->found();
	}

	/**
	 * Trash a post.
	 *
	 * `wp_trash_post()`, not the ORM's delete(): core only auto-trashes literal post and page, so a
	 * custom post type handed to wp_delete_post() is removed permanently with no way back. Refuses
	 * outright when trash is disabled rather than silently escalating to a permanent delete.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function trash( int $post_id ) {
		if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) {
			return new WP_Error(
				'trash_disabled',
				__( 'Trash is disabled on this site (EMPTY_TRASH_DAYS is 0), so this would delete permanently rather than trash. Refusing — remove it from the calendar UI if that is what you intend.', 'acrossai-abilities-manager' )
			);
		}

		$trashed = wp_trash_post( $post_id );

		if ( ! $trashed ) {
			return new WP_Error(
				'trash_failed',
				sprintf(
					/* translators: %d: post ID */
					__( 'WordPress refused to trash %d.', 'acrossai-abilities-manager' ),
					$post_id
				)
			);
		}

		return true;
	}

	/**
	 * Calendar settings, with credentials removed.
	 *
	 * The settings blob is shared by the calendar, the ticketing plugin and every add-on, and it
	 * holds API keys and OAuth tokens alongside ordinary preferences. The plugin has its own notion
	 * of a private option — a substring match on `_key` and `_token` — which is reused here and
	 * then widened, because that rule alone misses nothing today only by luck.
	 *
	 * Licence keys are NOT in this blob; they are separate `pue_install_key_*` option rows, which
	 * this suite never reads.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function settings(): array {
		$options = (array) get_option( 'tribe_events_calendar_options', array() );
		$rows    = array();

		foreach ( $options as $key => $value ) {
			$key = (string) $key;

			$rows[] = array(
				'key'      => $key,
				'value'    => self::is_secret( $key ) ? null : ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ),
				'redacted' => self::is_secret( $key ),
			);
		}

		return $rows;
	}

	/**
	 * Whether a settings key holds a credential.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return bool
	 */
	public static function is_secret( string $key ): bool {
		$named = array(
			'google_maps_js_api_key',
			'meetup_api_key',
			'meetup_security_key',
			'eb_security_key',
			'fb_token',
			'fb_token_expires',
			'fb_token_scopes',
		);

		if ( in_array( $key, $named, true ) ) {
			return true;
		}

		return 1 === preg_match( '/(_key|_token|_secret|password|licen[cs]e|signup_data|access_token)/i', $key );
	}

	/**
	 * Replace, add or remove event categories on an event.
	 *
	 * Taxonomy terms are ordinary WordPress terms — they are not duplicated into the calendar's
	 * custom tables — so this uses core term functions rather than the ORM.
	 *
	 * @since  0.0.34
	 * @param  int             $post_id Event ID.
	 * @param  array<int, int> $term_ids Term IDs.
	 * @param  string          $mode    replace|add|remove.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function set_categories( int $post_id, array $term_ids, string $mode ) {
		$taxonomy = self::post_type( 'category' );

		foreach ( $term_ids as $term_id ) {
			if ( ! term_exists( (int) $term_id, $taxonomy ) ) {
				return new WP_Error(
					'unknown_term',
					sprintf(
						/* translators: 1: term ID, 2: taxonomy */
						__( 'No term %1$d in %2$s.', 'acrossai-abilities-manager' ),
						(int) $term_id,
						$taxonomy
					)
				);
			}
		}

		if ( 'remove' === $mode ) {
			wp_remove_object_terms( $post_id, $term_ids, $taxonomy );
		} else {
			wp_set_object_terms( $post_id, $term_ids, $taxonomy, 'add' === $mode );
		}

		return self::term_rows( $post_id );
	}

	/**
	 * Event categories with the number of events in each.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::post_type( 'category' ),
				'hide_empty' => false,
			)
		);

		$rows = array();

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $rows;
		}

		foreach ( $terms as $term ) {
			$rows[] = array(
				'id'     => (int) $term->term_id,
				'slug'   => (string) $term->slug,
				'name'   => (string) $term->name,
				'parent' => (int) $term->parent,
				'events' => (int) $term->count,
			);
		}

		return $rows;
	}

	/**
	 * Shape one event for output.
	 *
	 * Rows and scalars only — no nested lazy objects, which do not survive JSON encoding, and no
	 * name-keyed maps in `array`-typed properties (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT).
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	public static function describe_event( int $post_id ): ?array {
		if ( ! function_exists( 'tribe_get_event' ) ) {
			return null;
		}

		$event = \tribe_get_event( $post_id );

		if ( ! $event instanceof WP_Post ) {
			return null;
		}

		$venues     = array();
		$organizers = array();

		foreach ( self::collection_to_ids( $event->venues ?? null ) as $id ) {
			$venues[] = self::describe_linked( (int) $id, 'venue' );
		}

		foreach ( self::collection_to_ids( $event->organizers ?? null ) as $id ) {
			$organizers[] = self::describe_linked( (int) $id, 'organizer' );
		}

		return array(
			'id'           => (int) $event->ID,
			'title'        => (string) get_the_title( $event->ID ),
			'status'       => (string) $event->post_status,
			'permalink'    => (string) get_permalink( $event->ID ),
			'start_date'   => (string) ( $event->start_date ?? '' ),
			'end_date'     => (string) ( $event->end_date ?? '' ),
			'start_utc'    => (string) ( $event->start_date_utc ?? '' ),
			'end_utc'      => (string) ( $event->end_date_utc ?? '' ),
			'timezone'     => (string) ( $event->timezone ?? '' ),
			'all_day'      => ! empty( $event->all_day ),
			'multiday'     => ! empty( $event->multiday ),
			'is_past'      => ! empty( $event->is_past ),
			'featured'     => ! empty( $event->featured ),
			'sticky'       => ! empty( $event->sticky ),
			'cost'         => (string) ( $event->cost ?? '' ),
			'url'          => (string) get_post_meta( $event->ID, '_EventURL', true ),
			'recurring'    => self::is_recurring( (int) $event->ID ),
			'venues'       => $venues,
			'organizers'   => $organizers,
			'categories'   => self::term_rows( (int) $event->ID ),
		);
	}

	/**
	 * A venue or organizer, shaped.
	 *
	 * @since  0.0.34
	 * @param  int    $post_id Post ID.
	 * @param  string $which   venue|organizer.
	 * @return array<string, mixed>
	 */
	public static function describe_linked( int $post_id, string $which ): array {
		$row = array(
			'id'    => $post_id,
			'title' => (string) get_the_title( $post_id ),
		);

		$keys = 'venue' === $which
			? array(
				'address'  => '_VenueAddress',
				'city'     => '_VenueCity',
				'state'    => '_VenueStateProvince',
				'zip'      => '_VenueZip',
				'country'  => '_VenueCountry',
				'phone'    => '_VenuePhone',
				'website'  => '_VenueURL',
			)
			: array(
				'phone'   => '_OrganizerPhone',
				'email'   => '_OrganizerEmail',
				'website' => '_OrganizerWebsite',
			);

		foreach ( $keys as $field => $meta_key ) {
			$row[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
		}

		return $row;
	}

	/**
	 * Category terms on an event, as rows.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function term_rows( int $post_id ): array {
		$terms = get_the_terms( $post_id, self::post_type( 'category' ) );
		$rows  = array();

		if ( ! is_array( $terms ) ) {
			return $rows;
		}

		foreach ( $terms as $term ) {
			$rows[] = array(
				'id'   => (int) $term->term_id,
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
			);
		}

		return $rows;
	}

	/**
	 * IDs out of a lazy post collection.
	 *
	 * The decorated event exposes `venues` and `organizers` as collections, not scalars — there is
	 * no singular `->venue`. They are lazily resolved and must not be handed to json_encode.
	 *
	 * @since  0.0.34
	 * @param  mixed $collection Lazy collection, array, or null.
	 * @return array<int, int>
	 */
	private static function collection_to_ids( $collection ): array {
		if ( null === $collection ) {
			return array();
		}

		if ( is_object( $collection ) && method_exists( $collection, 'all' ) ) {
			$collection = $collection->all();
		}

		$ids = array();

		foreach ( (array) $collection as $item ) {
			if ( $item instanceof WP_Post ) {
				$ids[] = (int) $item->ID;
				continue;
			}

			if ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			}
		}

		return $ids;
	}
}
