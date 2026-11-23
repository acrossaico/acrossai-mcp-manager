<?php
/**
 * REST sub-controller: list and single-item reads.
 *
 * Handles:
 *   GET /abilities          — paginated list with search, source, status filters
 *   GET /abilities/{id}     — single ability by integer ID
 *
 * All filtering, sorting, and pagination is delegated to query layer classes:
 * - source=db  → AcrossAI_Abilities_Query (DB table only, includes drafts)
 * - all others → AcrossAI_Ability_Registry_Query (WP registry + DB overrides merged)
 * (AC-QUERY-LAYER-FILTERING constraint — no post-query filter logic in REST controllers).
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Rest
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Query;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Abilities_Formatter;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Merger;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Registry_Query;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Sanitizer;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group;
use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Integrations;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles read operations for the unified abilities catalog.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Read_Controller {


	/**
	 * Register literal-segment routes that must win over the slug wildcard.
	 *
	 * Called by the orchestrator BEFORE any sub-controller's register_routes(), because
	 * AcrossAI_Abilities_Write_Controller registers '/abilities/(?P<slug>[^/]+)' and runs first.
	 * WP_REST_Server::dispatch() walks routes in registration order and takes the first regex
	 * match, so a literal registered after that wildcard is unreachable — '/abilities/toolsets'
	 * would dispatch as an ability named "toolsets" and 404
	 * (BUG-REST-ROUTE-ORDER-LITERAL-BEFORE-WILDCARD).
	 *
	 * Registering it inside register_routes() is NOT sufficient, even placed above this
	 * controller's own wildcard — verified live: the route landed at index 4 with the wildcard
	 * already at index 2.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function register_literal_routes(): void {
		$permission = array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' );

		// Toolset counts. MUST be registered before the '/abilities/(?P<slug>[^/]+)' route below:
		// WordPress matches in registration order, so a wildcard registered first would swallow
		// 'toolsets' as an ability slug (BUG-REST-ROUTE-ORDER-LITERAL-BEFORE-WILDCARD).
		//
		// Counts are served here, and not localised into the admin page, because
		// AcrossAI_Ability_Override_Processor prunes site_allowed=false abilities on every request
		// EXCEPT this namespace (PATH A/B). A count taken during page render would omit exactly the
		// blocked abilities the screen then lists (BUG-PATH-B-AGGREGATE-UNDERCOUNT).
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/abilities/toolsets',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_toolset_counts' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * GET /abilities/toolsets — ability count per toolset.
	 *
	 * Served from this namespace deliberately. AcrossAI_Ability_Override_Processor prunes
	 * site_allowed=false abilities from the registry on every request except this one (PATH A/B),
	 * so a count taken anywhere else — notably during an admin page render — would omit exactly the
	 * blocked abilities the abilities screen then lists, and the strip would contradict the table
	 * (BUG-PATH-B-AGGREGATE-UNDERCOUNT).
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_toolset_counts( \WP_REST_Request $request ) {
		unset( $request );

		// The overall total ships with the per-toolset counts rather than being read off the list
		// response: the list's total is the *filtered* total, so on a toolset view it is that
		// toolset's count — using it for the "All" entry made All read 7 while 419 abilities
		// existed. Counted here, on the same PATH A read as the counts, so the two agree.
		// Counts what the LIST returns, dispatchers included. The list stopped
		// excluding protected slugs; had this kept excluding them the strip
		// would have read "All 376" above a table showing 399 — the same
		// contradiction BUG-PATH-B-AGGREGATE-UNDERCOUNT describes, arrived at
		// from the other direction.
		//
		// The per-group counts below still exclude them, and that is not an
		// inconsistency: a dispatcher is in the inventory but is not a MEMBER
		// of a toolset, so the tabs sum to less than All — which is already
		// true of every ability carrying no group at all.
		$total = count( wp_get_abilities() );

		return rest_ensure_response(
			array(
				'counts' => AcrossAI_Ability_Group::counts(),
				'total'  => $total,
				// Only groups whose integration declares a name. Everything else is derived from the
				// key client-side, so this stays small (issue #184).
				'labels' => AcrossAI_Toolset_Integrations::labels(),
			)
		);
	}

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Abilities_Read_Controller|null
	 */
	protected static $instance = null;

	/**
	 * DB query instance (custom abilities, source=db).
	 *
	 * @var AcrossAI_Abilities_Query
	 */
	private $db_query;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Read_Controller
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->db_query = AcrossAI_Abilities_Query::instance();
	}

	/**
	 * Register REST routes owned by this controller.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' );

		// List.
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/abilities',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_abilities' ),
					'permission_callback' => $permission,
					'args'                => array(
						'page'      => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page'  => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'search'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'orderby'   => array(
							'type'    => 'string',
							'enum'    => array( 'ability_slug', 'label', 'status', 'source', 'updated_at', 'created_at' ),
							'default' => 'ability_slug',
						),
						'order'     => array(
							'type'    => 'string',
							'enum'    => array( 'asc', 'desc', 'ASC', 'DESC' ),
							'default' => 'asc',
						),
						'source'    => array(
							'type' => 'string',
							'enum' => array( 'db', 'plugin', 'theme', 'core', '' ),
						),
						'status'    => array(
							'type' => 'string',
							'enum' => array( 'draft', 'publish', '' ),
						),
						'category'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'editable'  => array(
							'type' => 'string',
							'enum' => array( 'true', 'false', '1', '0', '' ),
						),
						'tab_group' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Single item.
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/abilities/(?P<slug>[^/]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ability' ),
					'permission_callback' => $permission,
					'args'                => array(
						'slug' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => function ( $slug ) {
								// SEC-003: rawurldecode runs before sanitize_ability_slug so %2F-encoded
								// slashes in namespaced slugs are handled correctly. The allowlist regex
								// in sanitize_ability_slug strips all non-whitelisted chars post-decode.
								return AcrossAI_Sanitizer::sanitize_ability_slug( rawurldecode( (string) $slug ) );
							},
							'validate_callback' => function ( $slug ) {
								return is_string( $slug ) && '' !== trim( $slug );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Handle GET /abilities — paginated list.
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_abilities( \WP_REST_Request $request ) {
		$source = (string) ( $request->get_param( 'source' ) ?? '' );
		$status = (string) ( $request->get_param( 'status' ) ?? '' );

		// source=db: query the custom abilities table only (includes drafts).
		//
		// status=draft is routed here too. Only a DB-created ability can be a draft, and a draft is
		// never registered, so wp_get_abilities() cannot satisfy the filter — the registry branch
		// would return an empty set and read as broken. Feature 102 FR-015a.
		if ( 'db' === $source || 'draft' === $status ) {
			$params   = array(
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
				'search'   => $request->get_param( 'search' ),
				'orderby'  => $request->get_param( 'orderby' ),
				'order'    => $request->get_param( 'order' ),
				'source'   => $source,
				'status'   => $request->get_param( 'status' ),
				'category' => $request->get_param( 'category' ),
				'editable' => $request->get_param( 'editable' ),
			);
			$result   = $this->db_query->get_paginated( $params );
			$response = rest_ensure_response( AcrossAI_Abilities_Formatter::format_collection( $result['items'] ) );
			$response->header( 'X-WP-Total', (string) $result['total'] );
			$response->header( 'X-WP-TotalPages', (string) $result['pages'] );
			return $response;
		}

		// All other cases: merge WP registry abilities with DB overrides.
		// This returns all WP-registered abilities (plugin/theme/core + published DB abilities)
		// merged with any stored site overrides — the same data set shown on the sitewide page.
		// Resolve a toolset to its members through AcrossAI_Ability_Group — never by re-reading
		// meta.acrossai.tab_group here. That class is the single seam and already excludes protected
		// slugs, which is what keeps the toolset dispatcher abilities out of their own toolsets
		// (DEC-PROTECTED-SLUGS-PATTERN).
		$tab_group = (string) ( $request->get_param( 'tab_group' ) ?? '' );
		$slugs     = ( '' !== $tab_group ) ? AcrossAI_Ability_Group::member_names( $tab_group ) : null;

		$registry_params = array(
			'search'   => (string) ( $request->get_param( 'search' ) ?? '' ),
			'orderby'  => 'ability_slug' === $request->get_param( 'orderby' ) ? 'slug' : (string) ( $request->get_param( 'orderby' ) ?? 'slug' ),
			'order'    => (string) ( $request->get_param( 'order' ) ?? 'asc' ),
			'source'   => $source,
			'page'     => (int) ( $request->get_param( 'page' ) ?? 1 ),
			'per_page' => (int) ( $request->get_param( 'per_page' ) ?? 20 ),
			'status'   => $status,
			'slugs'    => $slugs,
		);

		$result   = AcrossAI_Ability_Registry_Query::query( $registry_params, $this->db_query );
		$response = rest_ensure_response( AcrossAI_Abilities_Formatter::format_merged_collection( $result['abilities'] ) );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );

		return $response;
	}

	/**
	 * Handle GET /abilities/{slug} — single ability by slug.
	 *
	 * Tries source=db row first; falls back to WP registry + override merge.
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_ability( \WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' ); // Pre-sanitized via route arg sanitize_callback.

		// Try DB-managed ability first.
		$row = $this->db_query->get_ability_by_slug( $slug );
		if ( null !== $row && 'db' === $row->source ) {
			return rest_ensure_response( AcrossAI_Abilities_Formatter::format_for_response( $row ) );
		}

		// Fall back to WP registry + override merge.
		$registry_raw = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
		if ( null === $registry_raw ) {
			return new \WP_Error( 'rest_not_found', __( 'Ability not found.', 'acrossai-abilities-manager' ), array( 'status' => 404 ) );
		}

		$override_row = $this->db_query->get_override_by_slug( $slug );
		$registry     = AcrossAI_Ability_Merger::normalize_registry( $registry_raw );
		$merged       = AcrossAI_Ability_Merger::merge( $registry, $override_row );
		return rest_ensure_response( AcrossAI_Abilities_Formatter::format_merged_ability( $merged ) );
	}
}
