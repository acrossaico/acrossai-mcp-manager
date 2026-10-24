<?php
/**
 * BerlinDB Query for the ActivityLog module (Feature 096).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ActivityLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Query subclass for `{prefix}acrossai_mcp_activity_log`.
 *
 * Owns every read of the activity log. Writes go through Recorder, never
 * through add_item() directly — A15 makes Recorder the audit-write boundary so
 * no caller can bypass its try/catch shielding.
 *
 * Bespoke reads added here:
 *   - children()            the one-to-many read two tables would have needed a
 *                           second controller and a second endpoint to express
 *   - count_children()      for the "abilities run (N)" affordance
 *   - prune_by_event_type() retention, per event kind (never one global cutoff)
 *   - enforce_ceiling()     the retained-event bound, oldest first
 */
class Query extends \BerlinDB\Database\Kern\Query {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = ActivityLogConstants::TABLE;

	/**
	 * SQL alias used in JOIN expressions.
	 *
	 * @var string
	 */
	protected $table_alias = 'aal';

	/**
	 * Schema class for this query.
	 *
	 * @var string
	 */
	protected $table_schema = Schema::class;

	/**
	 * Singular item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name = 'activity_log';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'activity_logs';

	/**
	 * Row class for query results.
	 *
	 * @var string
	 */
	protected $item_shape = Row::class;

	/** Transient key prefix for the cached aggregates. */
	private const CACHE_PREFIX = 'acrossai_mcp_activity_';

	/**
	 * Cache lifetime, in seconds.
	 *
	 * Five minutes rather than an hour: the stat tiles describe "right now" and
	 * an operator watching a connector fail needs the numbers to move. Writes
	 * invalidate explicitly anyway, so the TTL only covers the case where
	 * nothing has been recorded.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Singleton instance.
	 *
	 * @var Query|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — enforces the singleton pattern (A2/S6).
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Visibility override (public → private) enforces the singleton pattern per A2/S6.
		parent::__construct();
	}

	/**
	 * Get the singleton instance.
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
	 * Attach already-written ability rows to the request that caused them.
	 *
	 * The ordering this exists for: an ability row is written while the request
	 * runs, the request's own row only at the end once its duration is known.
	 * The parent therefore does not exist when its children are stored, so the
	 * link is made afterwards rather than at insert time.
	 *
	 * `invoked_via` is corrected in the same pass. It is derived at write time
	 * from `parent_id`, which is 0 then, so an MCP-originated ability lands
	 * labelled `rest`. Being adopted by a request event is proof it was MCP —
	 * that event is only ever emitted for an MCP dispatch — so the two
	 * corrections belong together and a caller cannot apply one without the
	 * other.
	 *
	 * Row by row through `update_item()`, NOT one bulk UPDATE. A raw
	 * `$wpdb->query()` bypasses BerlinDB's object cache, whose invalidation
	 * methods are all private, so cached reads would keep serving `parent_id`
	 * 0 and the detail panel would still show nothing — the same trap SC-C21
	 * records for the privacy eraser. N is small here (the abilities of one
	 * tool call) and the cap is enforced upstream by CaptureContext.
	 *
	 * @param int             $parent_id Request row id.
	 * @param array<int, int> $child_ids Ability row ids to adopt.
	 * @return int Rows successfully linked.
	 */
	public function adopt_children( int $parent_id, array $child_ids ): int {
		if ( $parent_id <= 0 || empty( $child_ids ) ) {
			return 0;
		}

		$linked = 0;

		foreach ( $child_ids as $child_id ) {
			$child_id = (int) $child_id;

			// A row cannot be its own parent. Defensive rather than expected:
			// the ids come from two different writers, and a cycle here would
			// make children() recurse.
			if ( $child_id <= 0 || $child_id === $parent_id ) {
				continue;
			}

			$updated = $this->update_item(
				$child_id,
				array(
					'parent_id'   => $parent_id,
					'invoked_via' => 'mcp',
				)
			);

			if ( $updated ) {
				++$linked;
			}
		}

		if ( $linked > 0 ) {
			self::flush_cache();
		}

		return $linked;
	}

	/**
	 * Drop the cached aggregates.
	 *
	 * Called on every write and on purge. Explicit invalidation rather than
	 * TTL-only, because a freshly recorded call that does not appear in the
	 * tiles reads as "the log is broken".
	 */
	public static function flush_cache(): void {
		global $wpdb;

		// Transient names are not enumerable through the options API, so sweep
		// by prefix. Harmless when an object cache is in play: the TTL covers it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_' . self::CACHE_PREFIX . '%'
			)
		);

		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}

	// --- Bespoke reads ---

	/**
	 * The ability events recorded beneath one request event.
	 *
	 * @param int $parent_id Request event id.
	 * @param int $limit     Maximum rows. 0 for no limit.
	 * @return array<int, Row>
	 */
	public function children( int $parent_id, int $limit = 0 ): array {
		if ( $parent_id <= 0 ) {
			return array();
		}

		$args = array(
			'parent_id' => $parent_id,
			'orderby'   => 'id',
			'order'     => 'ASC',
		);

		if ( $limit > 0 ) {
			$args['number'] = $limit;
		}

		$rows = $this->query( $args );

		// Narrow for real rather than asserting: BerlinDB's query() can return
		// ids instead of row objects depending on args, and handing those back
		// as Rows would be a lie the caller cannot detect.
		$out = array();

		foreach ( (array) $rows as $row ) {
			if ( $row instanceof Row ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * How many ability events were recorded beneath one request event.
	 *
	 * @param int $parent_id Request event id.
	 * @return int
	 */
	public function count_children( int $parent_id ): int {
		if ( $parent_id <= 0 ) {
			return 0;
		}

		$count = $this->query(
			array(
				'parent_id' => $parent_id,
				'count'     => true,
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * One page of events, with keyset pagination when a cursor is supplied.
	 *
	 * **This helper is net-new.** An earlier draft of the plan claimed it reused
	 * an existing one; verified otherwise — no REST controller in this plugin
	 * paginates at all, so there was nothing to reuse (recorded as B70). It is
	 * built here in a shape Feature 097 can consume.
	 *
	 * Offset pagination degrades on deep pages because MySQL still walks the
	 * skipped rows. Keyset does not: `( created_at < %s ) OR ( created_at = %s
	 * AND id < %d )` seeks straight into the index. The `id` tiebreaker is not
	 * optional — `created_at` has second granularity and a chatty client puts
	 * many events in the same second, so without it a page boundary either
	 * repeats or skips rows.
	 *
	 * @param array<string, mixed> $filters          Column filters (allow-listed by the caller).
	 * @param int                  $per_page         Rows per page.
	 * @param string               $cursor_timestamp `created_at` of the last row seen, or ''.
	 * @param int                  $cursor_id        `id` of the last row seen, or 0.
	 * @return array{rows: array<int, Row>, next_cursor: array{timestamp: string, id: int}|null}
	 */
	public function page_by_cursor( array $filters, int $per_page, string $cursor_timestamp = '', int $cursor_id = 0 ): array {
		global $wpdb;

		$per_page = max( 1, min( 100, $per_page ) );
		$table    = $wpdb->prefix . ActivityLogConstants::TABLE;

		$where  = array( '1 = 1' );
		$params = array();

		foreach ( $this->allowed_filters( $filters ) as $column => $value ) {
			$where[]  = "`{$column}` = " . ( is_int( $value ) ? '%d' : '%s' );
			$params[] = $value;
		}

		if ( isset( $filters['date_from'] ) && '' !== $filters['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $filters['date_from'];
		}

		if ( isset( $filters['date_to'] ) && '' !== $filters['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $filters['date_to'];
		}

		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '( component_name LIKE %s OR error_message LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		// The keyset predicate. Applied only when BOTH cursor halves are
		// present: one without the other would silently fall back to offset
		// behaviour while the caller believed it was seeking.
		if ( '' !== $cursor_timestamp && $cursor_id > 0 ) {
			$where[]  = '( created_at < %s OR ( created_at = %s AND id < %d ) )';
			$params[] = $cursor_timestamp;
			$params[] = $cursor_timestamp;
			$params[] = $cursor_id;
		}

		// Fetch one extra row to learn whether another page exists, without a
		// second COUNT query over the same filters.
		$params[] = $per_page + 1;

		$sql = 'SELECT * FROM `' . $table . '` WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY created_at DESC, id DESC LIMIT %d';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifiers come from $wpdb->prefix, a class constant, and the Schema allow-list in allowed_filters(); every VALUE is bound through $params.
		$raw = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$raw      = is_array( $raw ) ? $raw : array();
		$has_more = count( $raw ) > $per_page;
		$raw      = array_slice( $raw, 0, $per_page );
		$rows     = array();

		foreach ( $raw as $item ) {
			$rows[] = new Row( $item );
		}

		$next = null;

		if ( $has_more && ! empty( $rows ) ) {
			$last = $rows[ count( $rows ) - 1 ];
			$next = array(
				'timestamp' => (string) $last->created_at,
				'id'        => (int) $last->id,
			);
		}

		return array(
			'rows'        => $this->label_connectors( $rows ),
			'next_cursor' => $next,
		);
	}

	/**
	 * Keep only filter keys that are real columns with scalar values.
	 *
	 * B7 applies to reads as much as writes the moment a caller-supplied key
	 * reaches SQL as an identifier. The allow-list is derived from the Schema
	 * rather than hardcoded, so a future column is filterable without a second
	 * list to keep in sync — and a forged key is dropped rather than injected.
	 *
	 * @param array<string, mixed> $filters Candidate filters.
	 * @return array<string, int|string>
	 */
	private function allowed_filters( array $filters ): array {
		static $columns = null;

		if ( null === $columns ) {
			$columns = array();

			foreach ( ( new Schema() )->columns as $column ) {
				$name = is_object( $column )
					? (string) ( $column->name ?? '' )
					: (string) ( is_array( $column ) && isset( $column['name'] ) ? $column['name'] : '' );

				if ( '' !== $name ) {
					$columns[ $name ] = true;
				}
			}
		}

		// created_at is handled by the explicit date and cursor predicates, not
		// as an equality filter — an exact-second match is never what a caller
		// means by "filter by date".
		$out = array();

		foreach ( $filters as $key => $value ) {
			if ( 'created_at' === $key || ! isset( $columns[ $key ] ) ) {
				continue;
			}

			if ( is_int( $value ) || ( is_string( $value ) && '' !== $value ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Period aggregates for the stat tiles.
	 *
	 * Four numbers an operator actually reads: how many requests, how long they
	 * took on average, what share succeeded, and how many distinct client
	 * applications were active. Deliberately NOT token counts — this table
	 * cannot report LLM usage, because the model runs on the client's side.
	 *
	 * @param int $days       Period length in days. Clamped to >= 1.
	 * @param int $server_id  Narrow to one server; 0 for all.
	 * @return array{requests: int, avg_duration_ms: int, success_rate: float, active_clients: int, context_bytes: int}
	 */
	public function summary( int $days = 1, int $server_id = 0 ): array {
		global $wpdb;

		$days = max( 1, $days );
		$key  = self::CACHE_PREFIX . 'summary_' . $days . '_' . $server_id;

		$cached = get_transient( $key );

		// B11: a transient can hold a partial write, a value of the wrong
		// shape, or a collision from another plugin's key. Validate the shape
		// before arithmetic — a stat tile is the last place a fatal should
		// surface, and an operator reading "0 requests" because of a type
		// surprise is worse than an uncached query.
		if ( is_array( $cached ) && isset( $cached['requests'] ) && is_numeric( $cached['requests'] ) ) {
			// Rebuilt key by key rather than returned raw. Checking one key and
			// trusting the rest is the half-measure B11 is about: a partial
			// write that happens to carry `requests` would then hand missing
			// keys straight to the tiles.
			return array(
				'requests'        => (int) $cached['requests'],
				'avg_duration_ms' => isset( $cached['avg_duration_ms'] ) && is_numeric( $cached['avg_duration_ms'] ) ? (int) $cached['avg_duration_ms'] : 0,
				'success_rate'    => isset( $cached['success_rate'] ) && is_numeric( $cached['success_rate'] ) ? (float) $cached['success_rate'] : 0.0,
				'active_clients'  => isset( $cached['active_clients'] ) && is_numeric( $cached['active_clients'] ) ? (int) $cached['active_clients'] : 0,
				'context_bytes'   => isset( $cached['context_bytes'] ) && is_numeric( $cached['context_bytes'] ) ? (int) $cached['context_bytes'] : 0,
			);
		}
		$table = $wpdb->prefix . ActivityLogConstants::TABLE;
		$empty = array(
			'requests'        => 0,
			'avg_duration_ms' => 0,
			'success_rate'    => 0.0,
			'active_clients'  => 0,
			'context_bytes'   => 0,
		);

		$where  = 'event_type = %s AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )';
		$params = array( ActivityLogConstants::EVENT_REQUEST, $days );

		if ( $server_id > 0 ) {
			$where   .= ' AND server_id = %d';
			$params[] = $server_id;
		}

		$sql = "SELECT COUNT(*) AS requests,
				AVG( duration_ms ) AS avg_duration_ms,
				SUM( CASE WHEN status = 'success' THEN 1 ELSE 0 END ) AS successes,
				COUNT( DISTINCT NULLIF( client_name, '' ) ) AS active_clients,
				COALESCE( SUM( output_bytes ), 0 ) AS context_bytes
				FROM `{$table}` WHERE {$where}";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The table identifier is $wpdb->prefix + a class constant and $where is assembled from literals above; every VALUE travels through $params and is bound by prepare(). $wpdb->prepare() cannot parameterise identifiers.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// B11: a partial write or a type surprise must not surface as a fatal
		// on the stat tiles. Validate before arithmetic, never after.
		if ( ! is_array( $row ) || ! isset( $row['requests'] ) || ! is_numeric( $row['requests'] ) ) {
			return $empty;
		}

		$requests  = (int) $row['requests'];
		$successes = isset( $row['successes'] ) && is_numeric( $row['successes'] ) ? (int) $row['successes'] : 0;

		$summary = array(
			'requests'        => $requests,
			'avg_duration_ms' => isset( $row['avg_duration_ms'] ) && is_numeric( $row['avg_duration_ms'] ) ? (int) round( (float) $row['avg_duration_ms'] ) : 0,
			'success_rate'    => $requests > 0 ? round( ( $successes / $requests ) * 100, 1 ) : 0.0,
			'active_clients'  => isset( $row['active_clients'] ) && is_numeric( $row['active_clients'] ) ? (int) $row['active_clients'] : 0,
			'context_bytes'   => isset( $row['context_bytes'] ) && is_numeric( $row['context_bytes'] ) ? (int) $row['context_bytes'] : 0,
		);

		set_transient( $key, $summary, self::CACHE_TTL );

		return $summary;
	}

	/**
	 * Distinct values for the filter controls.
	 *
	 * @param int $days Period length in days. Clamped to >= 1.
	 * @return array<string, array<int, string>> Column name => sorted distinct values.
	 */
	public function filter_options( int $days = 30 ): array {
		global $wpdb;

		$days = max( 1, $days );
		$key  = self::CACHE_PREFIX . 'filters_' . $days;

		$cached = get_transient( $key );

		// B11 again: validate the shape, not merely that something came back.
		if ( is_array( $cached ) && isset( $cached['event_type'] ) && is_array( $cached['event_type'] ) ) {
			return $cached;
		}

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;
		$out   = array();

		// Allow-listed against the schema, not caller-supplied — B7 applies to
		// reads as much as writes once a column name reaches SQL.
		$columns = array( 'event_type', 'connection_type', 'invoked_via', 'status', 'method', 'component_category' );

		foreach ( $columns as $column ) {
			$sql = "SELECT DISTINCT `{$column}` FROM `{$table}`
					WHERE `{$column}` <> '' AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )
					ORDER BY `{$column}` ASC";

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table and $column are plugin-owned identifiers; $column comes from the hardcoded allow-list above and never from request data. The one value is bound.
			$values = $wpdb->get_col( $wpdb->prepare( $sql, $days ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$out[ $column ] = is_array( $values ) ? array_values( array_map( 'strval', $values ) ) : array();
		}

		set_transient( $key, $out, self::CACHE_TTL );

		return $out;
	}

	/**
	 * Resolve connector slugs to display names, one pass per page of rows.
	 *
	 * Labels at READ time rather than denormalising a connector name into every
	 * row, after easy-mcp-ai's attach_labels(): a renamed connector then shows
	 * its new name on historical events instead of leaving the old one frozen
	 * into thousands of rows.
	 *
	 * `client_name` is the opposite case and stays denormalised — it names the
	 * client application from clientInfo and has no row to join to.
	 *
	 * @param array<int, Row> $rows Rows to label, modified in place.
	 * @return array<int, Row>
	 */
	public function label_connectors( array $rows ): array {
		$slugs = array();

		foreach ( $rows as $row ) {
			if ( '' !== $row->connector_slug ) {
				$slugs[ $row->connector_slug ] = true;
			}
		}

		if ( empty( $slugs ) ) {
			return $rows;
		}

		/**
		 * Filters connector slug => display name for a page of activity rows.
		 *
		 * One pass per page. Consumers resolve every slug at once rather than
		 * being called per row.
		 *
		 * The `|mixed` on $labels is not sloppiness: a filter return is third-party
		 * code and can be any type at all. Narrowing it here would make PHPStan
		 * trust it and flag the defensive checks below as redundant — which is
		 * precisely backwards, since those checks are the only thing standing
		 * between a badly-behaved filter and a fatal on the activity screen.
		 *
		 * @param array<string, string>|mixed $labels Slug => display name.
		 * @param array<int, string>          $slugs  The distinct slugs on this page.
		 */
		$labels = apply_filters(
			'acrossai_mcp_activity_connector_labels',
			array(),
			array_keys( $slugs )
		);

		if ( ! is_array( $labels ) ) {
			return $rows;
		}

		foreach ( $rows as $row ) {
			if ( isset( $labels[ $row->connector_slug ] ) && is_string( $labels[ $row->connector_slug ] ) ) {
				$row->connector_label = $labels[ $row->connector_slug ];
			}
		}

		return $rows;
	}

	/**
	 * Remove every row in the table.
	 *
	 * Lives here, not in the REST controller that calls it. Every other delete
	 * in this module goes through the Query layer — `prune_by_event_type()` and
	 * `enforce_ceiling()` below — and the purge was the single place the entry
	 * boundary knew the physical table name and issued its own SQL. Correct, but
	 * the asymmetry is invisible from the controller and is exactly the kind of
	 * thing the next one copies.
	 *
	 * A raw statement rather than paging through `delete_item()`: this removes
	 * everything, so there is nothing to invalidate selectively, and the cache is
	 * flushed wholesale immediately after. That makes it the opposite case to
	 * SC-C21, where a bulk UPDATE left cached rows serving stale values — here
	 * no row survives for a stale read to find.
	 *
	 * Does NOT write the removal record. The caller owns that, because only the
	 * caller knows who asked (A24 requires the actor).
	 *
	 * @return int Rows removed.
	 */
	public function purge_all(): int {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is $wpdb->prefix + a class constant; the statement takes no values, so there is nothing for prepare() to bind.
		$removed = $wpdb->query( "DELETE FROM `{$table}`" );

		self::flush_cache();

		return is_numeric( $removed ) ? (int) $removed : 0;
	}

	/**
	 * Delete events of one kind older than a cutoff.
	 *
	 * Per event kind, never one global cutoff: ability events outnumber request
	 * events by a wide margin and should age out sooner. A single cutoff would
	 * force the noisy half's retention onto the quiet half.
	 *
	 * @param string $event_type One of ActivityLogConstants::EVENT_TYPES.
	 * @param int    $days       Age in days. 0 or less deletes nothing.
	 * @return int Rows deleted.
	 */
	public function prune_by_event_type( string $event_type, int $days ): int {
		global $wpdb;

		if ( $days <= 0 || ! in_array( $event_type, ActivityLogConstants::EVENT_TYPES, true ) ) {
			return 0;
		}

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is prefix + a class constant; the two variables are prepared.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE event_type = %s AND created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier only ($wpdb->prefix + a class constant); both values bound.
				$event_type,
				$days
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Trim the oldest events once the retained-event ceiling is exceeded.
	 *
	 * Retention alone leaves worst-case size a function of traffic. This bounds
	 * it. Sampling was rejected: it would make the success-rate tile and the
	 * context-size ranking estimates rather than facts, and an operator cannot
	 * tell a sampled log from a quiet one.
	 *
	 * @param int $max_events Ceiling. 0 or less disables trimming.
	 * @return int Rows deleted.
	 */
	public function enforce_ceiling( int $max_events ): int {
		global $wpdb;

		if ( $max_events <= 0 ) {
			return 0;
		}

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is prefix + a class constant; the bound is prepared.
		$cutoff_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1 OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier only; the bound is bound.
				$max_events
			)
		);

		if ( ! is_numeric( $cutoff_id ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is prefix + a class constant; the bound is prepared.
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE id <= %d", (int) $cutoff_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier only; the bound is bound.
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
