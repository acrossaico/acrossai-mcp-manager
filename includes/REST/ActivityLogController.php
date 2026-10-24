<?php
/**
 * Feature 096 — MCP Activity Log REST controller.
 *
 * Six read routes plus a purge, all under the plugin's existing namespace:
 *
 *   GET    /acrossai-mcp-manager/v1/activity
 *   GET    /acrossai-mcp-manager/v1/activity/summary
 *   GET    /acrossai-mcp-manager/v1/activity/filters
 *   GET    /acrossai-mcp-manager/v1/activity/{id}
 *   GET    /acrossai-mcp-manager/v1/activity/{id}/children
 *   DELETE /acrossai-mcp-manager/v1/activity
 *
 * ONE controller, not two. The `event_type` parameter is what two controllers
 * would have been expressing, and it is honest here because every parameter
 * applies to the one table — those that only make sense for one event kind
 * narrow the result rather than erroring.
 *
 * Wired via `includes/Main.php::define_admin_hooks()` per A1.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\REST
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query as ActivityLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST surface over the activity log. Read-only apart from the purge.
 */
final class ActivityLogController {

	/** REST namespace shared with the rest of the plugin. */
	private const NS = 'acrossai-mcp-manager/v1';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/** Get the singleton instance. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private — hook wiring lives in Main::define_admin_hooks() per A1. */
	private function __construct() {}

	/**
	 * Register every route on `rest_api_init`.
	 *
	 * Each one declares an explicit `permission_callback`. `__return_true`
	 * appears nowhere in this controller: Principle III permits it only on
	 * public read endpoints, and S7 reserves the single existing exception for
	 * the OAuth token endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/activity',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'purge' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/activity/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_summary' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'days'      => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'server_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/activity/filters',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_filters' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'days' => array(
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/activity/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_event' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/activity/(?P<id>\d+)/children',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_children' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Capability gate for every route.
	 *
	 * `manage_options`, verified as this project's convention for admin
	 * controllers. There is no `AccessControl` class governing admin screens —
	 * `AccessControlTab` governs MCP *client* access per server, a different
	 * concern entirely.
	 *
	 * @return true|WP_Error
	 */
	public function permission_check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'acrossai_mcp_forbidden',
			__( 'You do not have permission to read the MCP activity log.', 'acrossai-mcp-manager' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * The 19 collection parameters.
	 *
	 * Every one is sanitised with the most specific callback available, and
	 * every column filter is additionally validated against the Schema inside
	 * the Query layer (B7) — a forged key is dropped there rather than reaching
	 * SQL as an identifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		// `validate_callback` is NOT optional here. WP_REST_Request::has_valid_params()
		// reads `if ( ! empty( $arg['validate_callback'] ) )` — so without it an
		// `enum` is declared and never enforced, and a bogus value falls through
		// to the query as an empty filter. Caught by test, not by inspection.
		$enum = static function ( array $values, string $fallback = '' ): array {
			return array(
				'type'              => 'string',
				'default'           => $fallback,
				'enum'              => array_merge( array( '' ), $values ),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			);
		};

		$text = array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		);

		$id = array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
		);

		return array(
			// Default to request events: the tree's top level. Ability children
			// arrive through /{id}/children or an explicit event_type.
			'event_type'         => $enum( ActivityLogConstants::EVENT_TYPES, ActivityLogConstants::EVENT_REQUEST ),
			'parent_id'          => $id,
			'server_id'          => $id,
			'user_id'            => $id,
			'connection_type'    => $enum( array( 'ai-connector', 'oauth', 'app-password', 'browser', 'unknown' ) ),
			'invoked_via'        => $enum( array( 'mcp', 'rest', 'cli', 'admin', 'unknown' ) ),
			'status'             => $enum( array( 'success', 'error', 'denied', 'invalid_input' ) ),
			'method'             => $text,
			'component_name'     => $text,
			'component_category' => $text,
			'date_from'          => $text,
			'date_to'            => $text,
			'search'             => $text,
			'page'               => array(
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'           => array(
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'orderby'            => $enum( array( 'timestamp', 'duration_ms', 'output_bytes' ), 'timestamp' ),
			'order'              => $enum( array( 'asc', 'desc' ), 'desc' ),
			'cursor_timestamp'   => $text,
			'cursor_id'          => $id,
		);
	}

	/**
	 * GET /activity — one page of events.
	 *
	 * Previews are deliberately absent from this response. A list of 100 rows
	 * each carrying a 2 KB preview is 200 KB the screen does not render; the
	 * detail route serves them when a row is actually opened.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_events( WP_REST_Request $request ): WP_REST_Response {
		$filters = $this->filters_from( $request );

		$result = ActivityLogQuery::instance()->page_by_cursor(
			$filters,
			(int) $request->get_param( 'per_page' ),
			(string) $request->get_param( 'cursor_timestamp' ),
			(int) $request->get_param( 'cursor_id' )
		);

		$events = array();

		foreach ( $result['rows'] as $row ) {
			$events[] = $this->shape( $row, false );
		}

		return new WP_REST_Response(
			array(
				'events'      => $events,
				'next_cursor' => $result['next_cursor'],
			)
		);
	}

	/**
	 * GET /activity/{id} — one event in full, previews included.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_event( WP_REST_Request $request ) {
		$row = ActivityLogQuery::instance()->get_item( (int) $request->get_param( 'id' ) );

		if ( ! $row instanceof Row ) {
			return new WP_Error(
				'acrossai_mcp_activity_not_found',
				__( 'No such activity event.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->shape( $row, true ) );
	}

	/**
	 * GET /activity/{id}/children — the abilities recorded beneath one request.
	 *
	 * The read that two tables would have needed a second controller to express.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_children( WP_REST_Request $request ): WP_REST_Response {
		$rows     = ActivityLogQuery::instance()->children( (int) $request->get_param( 'id' ) );
		$children = array();

		foreach ( $rows as $row ) {
			$children[] = $this->shape( $row, true );
		}

		return new WP_REST_Response( array( 'events' => $children ) );
	}

	/**
	 * GET /activity/summary — the stat tiles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_summary( WP_REST_Request $request ): WP_REST_Response {
		$summary = ActivityLogQuery::instance()->summary(
			(int) $request->get_param( 'days' ),
			(int) $request->get_param( 'server_id' )
		);

		// The approximate token figure is derived HERE, at read time, and never
		// stored. Bytes are a fact; a token count is a guess, and a guess in a
		// column named tokens_* gets aggregated into a cost report later.
		$summary['context_tokens_approx'] = $this->approx_tokens( $summary['context_bytes'] );

		return new WP_REST_Response( $summary );
	}

	/**
	 * GET /activity/filters — distinct values for the filter controls.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_filters( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			ActivityLogQuery::instance()->filter_options( (int) $request->get_param( 'days' ) )
		);
	}

	/**
	 * DELETE /activity — purge, and record that the purge happened.
	 *
	 * The removal record is written AFTER the delete, so it survives as the
	 * first entry of the remaining history (A24). Without it, destroying the
	 * log would be the one action the log cannot show.
	 *
	 * Touches only this table. Feature 097's change log is never reached from
	 * here, and its own cleanup never reaches this one.
	 *
	 * @return WP_REST_Response
	 */
	public function purge(): WP_REST_Response {
		$removed = ActivityLogQuery::instance()->purge_all();

		// Written here rather than inside purge_all(): only this layer knows who
		// asked. A24 requires the actor, and the Query layer has no acting user.
		Recorder::record_removal( 'purge', $removed, get_current_user_id() );

		return new WP_REST_Response(
			array(
				'removed' => $removed,
			)
		);
	}

	/**
	 * Collect column filters from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function filters_from( WP_REST_Request $request ): array {
		$filters = array();

		foreach ( array( 'event_type', 'connection_type', 'invoked_via', 'status', 'method', 'component_name', 'component_category' ) as $key ) {
			$value = (string) $request->get_param( $key );

			if ( '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		foreach ( array( 'parent_id', 'server_id', 'user_id' ) as $key ) {
			$value = (int) $request->get_param( $key );

			if ( $value > 0 ) {
				$filters[ $key ] = $value;
			}
		}

		// Dates arrive in the site's time zone and the column is UTC. Converting
		// here rather than in the Query keeps the storage layer unaware of
		// presentation, and means "last 24 hours" selects the same events the
		// retention job would treat as 24 hours old.
		$filters['date_from'] = $this->to_utc( (string) $request->get_param( 'date_from' ) );
		$filters['date_to']   = $this->to_utc( (string) $request->get_param( 'date_to' ) );
		$filters['search']    = (string) $request->get_param( 'search' );

		return $filters;
	}

	/**
	 * Convert a site-time-zone datetime string to UTC for comparison.
	 *
	 * @param string $local Datetime in the site's time zone, or ''.
	 * @return string UTC `Y-m-d H:i:s`, or '' when unparseable.
	 */
	private function to_utc( string $local ): string {
		if ( '' === $local ) {
			return '';
		}

		try {
			$date = new \DateTimeImmutable( $local, wp_timezone() );
		} catch ( \Exception $e ) {
			return '';
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Shape one row for the response.
	 *
	 * @param Row  $row              The event.
	 * @param bool $include_previews Whether to include the preview columns.
	 * @return array<string, mixed>
	 */
	private function shape( Row $row, bool $include_previews ): array {
		$shaped = array(
			'id'                   => $row->id,
			'parent_id'            => $row->parent_id,
			'event_type'           => $row->event_type,
			'event_source'         => $row->event_source,
			'server_id'            => $row->server_id,
			'server_slug'          => $row->server_slug,
			'connection_type'      => $row->connection_type,
			'connector_slug'       => $row->connector_slug,
			'connector_label'      => $row->connector_label,
			'invoked_via'          => $row->invoked_via,
			'client_name'          => $row->client_name,
			'client_version'       => $row->client_version,
			'transport'            => $row->transport,
			'method'               => $row->method,
			'protocol_version'     => $row->protocol_version,
			'component_type'       => $row->component_type,
			'component_name'       => $row->component_name,
			'invoked_name'         => $row->invoked_name,
			'component_category'   => $row->component_category,
			'status'               => $row->status,
			'error_code'           => $row->error_code,
			'error_message'        => $row->error_message,
			'duration_ms'          => $row->duration_ms,
			'user_id'              => $row->user_id,
			'ip_address'           => $row->ip_address,
			'user_agent'           => $row->user_agent,
			'session_id'           => $row->session_id,
			'request_id'           => $row->request_id,
			'input_bytes'          => $row->input_bytes,
			'output_bytes'         => $row->output_bytes,
			'output_tokens_approx' => $this->approx_tokens( $row->output_bytes ),
			'created_at'           => $row->created_at,
			'created_at_local'     => get_date_from_gmt( $row->created_at ),
		);

		if ( $include_previews ) {
			$shaped['input_preview']  = $row->input_preview;
			$shaped['output_preview'] = $row->output_preview;
		}

		return $shaped;
	}

	/**
	 * Derive an APPROXIMATE token count from a byte length.
	 *
	 * This log cannot report LLM token usage: the model runs on the client's
	 * side and neither MCP nor the adapter carries a usage block. What it can
	 * report is context cost — the bytes we handed back.
	 *
	 * Two filters rather than one constant. The divisor is tunable, and the
	 * whole estimate is replaceable, so a site with a real tokenizer (or a
	 * client that someday reports usage) substitutes the true number through
	 * the same seam. Returning null renders a dash instead of a guess.
	 *
	 * @param int|null $bytes Byte length, or null when unmeasured.
	 * @return int|null
	 */
	private function approx_tokens( ?int $bytes ): ?int {
		if ( null === $bytes ) {
			return null;
		}

		$divisor = (int) apply_filters(
			ActivityLogConstants::FILTER_BYTES_PER_TOKEN,
			ActivityLogConstants::DEFAULT_BYTES_PER_TOKEN
		);

		$estimate = $divisor > 0 ? (int) ceil( $bytes / $divisor ) : null;

		/**
		 * Filters the estimated token count for a logged payload.
		 *
		 * Return an exact count if something on this site can produce one.
		 * Return null to render an em dash rather than a guess.
		 *
		 * `|mixed` because a filter return is third-party code.
		 *
		 * @param int|null|mixed $estimate The byte-derived estimate.
		 * @param int            $bytes    The measured byte length.
		 */
		$filtered = apply_filters( ActivityLogConstants::FILTER_ESTIMATED_TOKENS, $estimate, $bytes );

		return is_numeric( $filtered ) ? (int) $filtered : null;
	}
}
