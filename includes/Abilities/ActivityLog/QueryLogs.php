<?php
/**
 * `mcp-manager/query-logs` — read the activity log.
 *
 * The screen at **AcrossAI → Logs** and the per-server **Logs** tab answer the
 * same question for a person; this answers it for an AI client. Both read the
 * one activity table, through {@see Query::page_by_cursor()}, so a filter that
 * works on screen works here and neither can drift from the other.
 *
 * ## Why this pages rather than returning everything
 *
 * A chatty client writes thousands of rows a day, and each carries an input and
 * an output preview. Returning a day of traffic unbounded would blow a model's
 * context in one call and bill the operator for it.
 *
 * So there are two separate numbers, and they are not the same thing:
 *
 *   `per_page`  how many rows one database page holds. Capped at 100 by
 *               `page_by_cursor()` itself, which is where the cap belongs.
 *   `limit`     how many rows this ONE CALL returns in total, paging
 *               internally until it has them. Defaults to 200.
 *
 * 200 is two pages: enough to see a pattern, small enough to read. When more
 * exists the answer says so and hands back a cursor — it does NOT keep going.
 * The caller is expected to show the operator what came back and ask before
 * spending their context on the rest, which is what `note` says in words a
 * model will act on.
 *
 * ## Keyset, not offset
 *
 * `page_by_cursor()` seeks on `( created_at, id )` rather than counting rows to
 * skip, so page 50 costs what page 1 does. The `id` tiebreaker matters: the
 * timestamp is second-granular and a busy client puts many events inside one
 * second, so an offset-free cursor without it would repeat or drop rows at
 * every page boundary.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities\ActivityLog
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\McpManager;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;

defined( 'ABSPATH' ) || exit;

/**
 * Paged, filtered reads of the activity log.
 *
 * @since 0.4.8
 */
final class QueryLogs {

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public const SLUG = 'mcp-manager/query-logs';

	/**
	 * Ability category. Registered by {@see SetupRequired}, and shared with the
	 * other abilities this plugin owns.
	 *
	 * @var string
	 */
	public const CATEGORY = 'acrossai-mcp';

	/**
	 * Rows returned when the caller names no limit.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 200;

	/**
	 * Rows per database page when the caller names none.
	 *
	 * Matches `page_by_cursor()`'s own ceiling, so the default is also the
	 * largest page it will ever serve.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 100;

	/**
	 * Hard ceiling on one call, however large a `limit` is asked for.
	 *
	 * A caller that genuinely wants more keeps paging with the returned cursor.
	 * What it does not get is ten thousand rows in one response.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 1000;

	/**
	 * Register the ability. Loader-wired from Main.php (A1).
	 *
	 * @since 0.4.8
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::SLUG ) ) {
			return;
		}

		wp_register_ability(
			self::SLUG,
			array(
				'label'               => __( 'Query activity log', 'acrossai-mcp-manager' ),
				'description'         => __(
					'Read this site\'s MCP activity log: which client called, what it ran, whether it worked, how long it took and how much context it returned. Filter by server to scope it to one MCP server, or leave server_id out to read every server at once. Also filters on event type, status, connection type, client, connector, fleet peer, component and a date range — call mcp-manager/get-log-filters first for the values this site actually holds. Returns 200 rows by default, paging internally at 100 a time; when more exists it hands back next_cursor rather than continuing, so ask the operator before fetching further. Reads only; changes nothing.',
					'acrossai-mcp-manager'
				),
				'category'            => self::CATEGORY,
				'meta'                => array(
					'acrossai' => array(
						// What puts this ability inside toolset/mcp-manager.
						'tab_group' => McpManager::GROUP,
					),
					'mcp'      => array(
						// public:true is required — ToolsetExposureBridge
						// defaults a member's visibility to this flag, so a
						// private member is invisible to its own dispatcher.
						'public' => true,
						// 'tool', not 'resource'. A resource-typed ability is
						// injected into every server's resources list by
						// AbilityDiscovery::for_server(), which would advertise
						// these directly to clients and bypass the dispatcher
						// entirely. Tools come from the curated per-server set
						// instead, and these are not in that pool, so the type
						// leaves them reachable only through toolset/mcp-manager.
						'type'   => 'tool',
					),
				),
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'events'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'count'       => array( 'type' => 'integer' ),
						'has_more'    => array( 'type' => 'boolean' ),
						'next_cursor' => array( 'type' => array( 'string', 'null' ) ),
						'filters'     => array( 'type' => 'object' ),
						'note'        => array( 'type' => 'string' ),
					),
					'required'   => array( 'events', 'count', 'has_more' ),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				// The log records who reached this site and what they ran, which
				// is operator-grade information — not something every logged-in
				// subscriber should read. Matches the Logs screen's own gate.
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * The accepted input.
	 *
	 * Every column filter is an exact match, because that is what
	 * `page_by_cursor()` supports — it builds `column = %s`, not a LIKE. Saying
	 * so in each description keeps a caller from passing a fragment and
	 * concluding the log is empty.
	 *
	 * @since  0.4.8
	 * @return array<string, mixed>
	 */
	private static function input_schema(): array {
		$text = static fn( string $description ): array => array(
			'type'        => 'string',
			'description' => $description,
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'server_id'        => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Scope to one MCP server by id. Omit, or pass 0, to read every server at once.', 'acrossai-mcp-manager' ),
				),
				'server_slug'      => $text( __( 'Scope to one MCP server by slug. Exact match.', 'acrossai-mcp-manager' ) ),
				'event_type'       => $text( __( 'Exact match, e.g. "request" for a call that arrived, or "ability_run" for one ability inside it.', 'acrossai-mcp-manager' ) ),
				'status'           => $text( __( 'Exact match, e.g. "success" or "error". Pair with event_type to read only failed ability runs.', 'acrossai-mcp-manager' ) ),
				'connection_type'  => $text( __( 'How the caller reached this site, e.g. "connector", "browser" or "fleet". Exact match.', 'acrossai-mcp-manager' ) ),
				'client_name'      => $text( __( 'Exact match on the reported client name.', 'acrossai-mcp-manager' ) ),
				'connector_slug'   => $text( __( 'Exact match on the connector slug.', 'acrossai-mcp-manager' ) ),
				'fleet_peer'       => $text( __( 'Exact match on the managing site that made the call. Use this to read what one hub did.', 'acrossai-mcp-manager' ) ),
				'component_name'   => $text( __( 'Exact match on the tool or ability name that ran.', 'acrossai-mcp-manager' ) ),
				'invoked_name'     => $text( __( 'Exact match on the name as the client invoked it, which can differ from component_name.', 'acrossai-mcp-manager' ) ),
				'method'           => $text( __( 'Exact match on the MCP method, e.g. "tools/call".', 'acrossai-mcp-manager' ) ),
				'parent_id'        => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Only events whose parent is this id — the ability runs inside one request. Prefer mcp-manager/get-log, which returns a request with its children already assembled.', 'acrossai-mcp-manager' ),
				),
				'date_from'        => $text( __( 'Only events at or after this time. Site timezone, "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".', 'acrossai-mcp-manager' ) ),
				'date_to'          => $text( __( 'Only events at or before this time. Same format as date_from.', 'acrossai-mcp-manager' ) ),
				'limit'            => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
					'default'     => self::DEFAULT_LIMIT,
					/* translators: 1: default row count, 2: hard ceiling. */
					'description' => sprintf( __( 'Total rows this call returns, paging internally to reach them. Defaults to %1$d; %2$d is the ceiling. When more exists you get next_cursor rather than more rows.', 'acrossai-mcp-manager' ), self::DEFAULT_LIMIT, self::MAX_LIMIT ),
				),
				'per_page'         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::DEFAULT_PER_PAGE,
					'default'     => self::DEFAULT_PER_PAGE,
					/* translators: %d: rows per page. */
					'description' => sprintf( __( 'Rows per database page while this call pages internally. Defaults to and is capped at %d; it changes cost, never the number of rows you get back — that is limit.', 'acrossai-mcp-manager' ), self::DEFAULT_PER_PAGE ),
				),
				'cursor'           => $text( __( 'Resume from a previous answer. Pass next_cursor back unchanged; do not construct one.', 'acrossai-mcp-manager' ) ),
				'include_previews' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Include the recorded input and output previews. Off by default because they are the largest field on a row and most questions are answered without them.', 'acrossai-mcp-manager' ),
				),
			),
			'additionalProperties' => false,
			// Core applies only the TOP-LEVEL default, and validate_input()
			// rejects null once a schema exists (B59) — so a zero-argument call
			// needs this or it fails before reaching the callback.
			'default'              => array(),
		);
	}

	/**
	 * Run the query.
	 *
	 * @since  0.4.8
	 * @param  mixed $input Validated input; untyped because core may pass
	 *                      anything and the guard below is what makes it safe.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$limit    = self::clamp( $input['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, self::DEFAULT_LIMIT );
		$per_page = self::clamp( $input['per_page'] ?? self::DEFAULT_PER_PAGE, 1, self::DEFAULT_PER_PAGE, self::DEFAULT_PER_PAGE );
		$previews = ! empty( $input['include_previews'] );

		$filters = self::filters_from( $input );

		list( $cursor_ts, $cursor_id ) = self::decode_cursor( (string) ( $input['cursor'] ?? '' ) );

		$query  = Query::instance();
		$events = array();
		$next   = null;

		// Page internally until `limit` is satisfied. `per_page` is never
		// allowed to overshoot the remaining budget, so the last page is sized
		// to what is still wanted rather than fetched and thrown away.
		$fetched = 0;

		while ( $fetched < $limit ) {
			$page = $query->page_by_cursor(
				$filters,
				(int) min( $per_page, $limit - $fetched ),
				$cursor_ts,
				$cursor_id
			);

			foreach ( $page['rows'] as $row ) {
				$events[] = self::shape( $row, $previews );
			}

			$fetched = count( $events );

			$next = $page['next_cursor'];

			if ( null === $next ) {
				break;
			}

			$cursor_ts = (string) $next['timestamp'];
			$cursor_id = (int) $next['id'];

			// A malformed cursor would otherwise spin this loop forever.
			if ( '' === $cursor_ts || $cursor_id <= 0 ) {
				$next = null;
				break;
			}
		}

		$has_more = null !== $next;

		return array(
			'events'      => $events,
			'count'       => count( $events ),
			'has_more'    => $has_more,
			'next_cursor' => $has_more ? self::encode_cursor( $cursor_ts, $cursor_id ) : null,
			'filters'     => $filters,
			'note'        => self::note( $has_more, count( $events ), $limit ),
		);
	}

	/**
	 * What to tell the caller about the rest of the result.
	 *
	 * Written as an instruction rather than a statistic because the consumer is
	 * a model: "there are more" invites it to loop and spend the operator's
	 * context without being asked, which is the behaviour the paging exists to
	 * prevent.
	 *
	 * @since  0.4.8
	 * @param  bool $has_more Whether a further page exists.
	 * @param  int  $count    Rows returned.
	 * @param  int  $limit    Rows asked for.
	 * @return string
	 */
	private static function note( bool $has_more, int $count, int $limit ): string {
		if ( ! $has_more ) {
			return __( 'Every matching event is included; there is no further page.', 'acrossai-mcp-manager' );
		}

		return sprintf(
			/* translators: 1: rows returned, 2: rows requested. */
			__( 'Returned %1$d of a requested %2$d, and more events match. Show these to the person first and ask whether to continue before calling again with cursor set to next_cursor — each further page costs them context. Narrowing by server_id, date_from or status is usually better than paging.', 'acrossai-mcp-manager' ),
			$count,
			$limit
		);
	}

	/**
	 * Column filters, taken from input and left for `Query` to allow-list.
	 *
	 * Deliberately not validated against the schema here. `allowed_filters()`
	 * checks every key against the table's real columns, so this stays correct
	 * when a column is added and cannot be the place a typo slips through.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $input Validated input.
	 * @return array<string, int|string>
	 */
	private static function filters_from( array $input ): array {
		$filters = array();

		$ints = array( 'server_id', 'parent_id' );

		foreach ( $ints as $key ) {
			if ( isset( $input[ $key ] ) && (int) $input[ $key ] > 0 ) {
				$filters[ $key ] = (int) $input[ $key ];
			}
		}

		$strings = array(
			'server_slug',
			'event_type',
			'status',
			'connection_type',
			'client_name',
			'connector_slug',
			'fleet_peer',
			'component_name',
			'invoked_name',
			'method',
			'date_from',
			'date_to',
		);

		foreach ( $strings as $key ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) && '' !== trim( $input[ $key ] ) ) {
				$filters[ $key ] = trim( $input[ $key ] );
			}
		}

		return $filters;
	}

	/**
	 * One row, as a plain array.
	 *
	 * Previews are omitted unless asked for — they are the largest field on a
	 * row, and most questions ("which client failed, and when?") never need
	 * them. Byte counts are always included, so a caller can see that a call
	 * returned a megabyte without being handed the megabyte.
	 *
	 * @since  0.4.8
	 * @param  Row  $row      One activity row.
	 * @param  bool $previews Whether to include input/output previews.
	 * @return array<string, mixed>
	 */
	public static function shape( Row $row, bool $previews = false ): array {
		$out = array(
			'id'              => (int) $row->id,
			'parent_id'       => (int) $row->parent_id,
			'created_at'      => (string) $row->created_at,
			'event_type'      => (string) $row->event_type,
			'event_source'    => (string) $row->event_source,
			'server_id'       => (int) $row->server_id,
			'server_slug'     => (string) $row->server_slug,
			'connection_type' => (string) $row->connection_type,
			'client_name'     => (string) $row->client_name,
			'client_version'  => (string) $row->client_version,
			'connector_slug'  => (string) $row->connector_slug,
			'connector_label' => (string) $row->connector_label,
			'fleet_peer'      => (string) $row->fleet_peer,
			'transport'       => (string) $row->transport,
			'method'          => (string) $row->method,
			'component_type'  => (string) $row->component_type,
			'component_name'  => (string) $row->component_name,
			'invoked_name'    => (string) $row->invoked_name,
			'status'          => (string) $row->status,
			'error_code'      => (string) $row->error_code,
			'error_message'   => (string) $row->error_message,
			'duration_ms'     => null === $row->duration_ms ? null : (int) $row->duration_ms,
			'user_id'         => (int) $row->user_id,
			'input_bytes'     => null === $row->input_bytes ? null : (int) $row->input_bytes,
			'output_bytes'    => null === $row->output_bytes ? null : (int) $row->output_bytes,
		);

		if ( $previews ) {
			$out['input_preview']  = $row->input_preview;
			$out['output_preview'] = $row->output_preview;
		}

		return $out;
	}

	/**
	 * Pack a keyset cursor into one opaque string.
	 *
	 * One field rather than two so a caller cannot pass half of it back, which
	 * silently returns page one again rather than erroring.
	 *
	 * @since  0.4.8
	 * @param  string $timestamp `created_at` of the last row seen.
	 * @param  int    $id        `id` of the last row seen.
	 * @return string
	 */
	public static function encode_cursor( string $timestamp, int $id ): string {
		return $timestamp . '|' . $id;
	}

	/**
	 * Unpack a cursor, tolerating anything.
	 *
	 * A cursor is a round-trip token, so a damaged one means "start at the
	 * beginning" rather than an error: the alternative is failing a read
	 * because a model reformatted a string it was told not to touch.
	 *
	 * @since  0.4.8
	 * @param  string $cursor Opaque cursor.
	 * @return array{0: string, 1: int}
	 */
	public static function decode_cursor( string $cursor ): array {
		// strpos(), not str_contains(): this plugin requires PHP 8.1, but the
		// PHPCompatibility sniff is configured against 7.4 and fails the build
		// on the newer function. Changing a repo-wide sniff config to shorten
		// one line is the wrong trade.
		if ( '' === $cursor || false === strpos( $cursor, '|' ) ) {
			return array( '', 0 );
		}

		list( $timestamp, $id ) = explode( '|', $cursor, 2 );

		$timestamp = trim( $timestamp );
		$id        = (int) trim( $id );

		return ( '' === $timestamp || $id <= 0 ) ? array( '', 0 ) : array( $timestamp, $id );
	}

	/**
	 * Coerce a supplied number into range.
	 *
	 * @since  0.4.8
	 * @param  mixed $value    Raw input.
	 * @param  int   $min      Lower bound.
	 * @param  int   $max      Upper bound.
	 * @param  int   $fallback Used when the value is not numeric.
	 * @return int
	 */
	private static function clamp( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}
}
