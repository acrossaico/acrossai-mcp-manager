<?php
/**
 * Attributing a logged event to the site at the other end of it.
 *
 * `ObservabilityHandlerTest` covers the recording — that a verified fleet
 * request resolves to `connection_type = fleet` and lands its peer in the row.
 * This file covers everything downstream of that, which was verified by hand
 * against a live pair of sites and then left unguarded: whether the peer is an
 * IDENTITY or merely a label, whether the REST layer will accept it as a
 * filter, and whether the filter-options cache serves a shape that predates
 * the column.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query as ActivityLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use WP_REST_Request;
use WP_UnitTestCase;

final class FleetPeerAttributionTest extends WP_UnitTestCase {

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();

		Table::instance()->maybe_upgrade();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . ActivityLogConstants::TABLE );

		delete_transient( 'acrossai_mcp_activity_filters_30' );
		wp_cache_flush();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . ActivityLogConstants::TABLE );
		delete_transient( 'acrossai_mcp_activity_filters_30' );
		parent::tear_down();
	}

	/**
	 * Record one request row.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return int Row id.
	 */
	private function record( array $overrides = array() ): int {
		return Recorder::record_request(
			array_merge(
				array(
					'server_slug'     => 'acrossai-fleet-server',
					'connection_type' => 'fleet',
					'fleet_peer'      => 'agency.example',
					'method'          => 'tools/call',
					'component_name'  => 'toolset/database',
					'status'          => 'success',
					'user_id'         => $this->admin_id,
				),
				$overrides
			)
		);
	}

	/**
	 * GET /activity with params.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @return \WP_REST_Response
	 */
	private function get( array $params ) {
		$request = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/activity' );

		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}

		return rest_get_server()->dispatch( $request );
	}

	// ─────────────────────────────────────────────────────────────────
	// The peer is an identity, not a label
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Two hubs that share a display name must stay distinguishable.
	 *
	 * The host list stores the hub's NAME (falling back to a hostname), and
	 * the first version of this column stored that. It reads nicely and it is
	 * not an identity: two agencies both calling themselves "My Agency" would
	 * be one indistinguishable peer in an audit log, and a hub renaming itself
	 * would split its own history in two. `RoutePermission` parses the
	 * hostname out of the stored URL instead, which is why.
	 */
	public function test_two_peers_sharing_a_display_name_remain_separate(): void {
		$this->record( array( 'fleet_peer' => 'agency-one.example' ) );
		$this->record( array( 'fleet_peer' => 'agency-two.example' ) );

		$one = $this->get( array( 'fleet_peer' => 'agency-one.example', 'per_page' => 50 ) );
		$two = $this->get( array( 'fleet_peer' => 'agency-two.example', 'per_page' => 50 ) );

		$this->assertCount( 1, $one->get_data()['events'] );
		$this->assertCount( 1, $two->get_data()['events'] );
	}

	/** `RoutePermission` must derive the peer from the URL, not the label. */
	public function test_the_route_takes_the_peer_from_the_url_not_the_stored_label(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Fleet/RoutePermission.php' );

		$this->assertMatchesRegularExpression(
			'/wp_parse_url\(\s*\(string\) \( \$host\[\x27url\x27\] \?\? \x27\x27 \),\s*PHP_URL_HOST\s*\)/',
			$source,
			'The peer must be the hostname parsed from the stored URL.'
		);

		// `$host['host']` is Register's display-name field. It may appear only
		// as the fallback, after the parsed hostname.
		$parsed   = strpos( $source, 'PHP_URL_HOST' );
		$fallback = strpos( $source, "FleetContext::mark(" );

		$this->assertNotFalse( $parsed );
		$this->assertNotFalse( $fallback );
		$this->assertLessThan( $fallback, $parsed, 'The hostname must be resolved before it is handed to FleetContext.' );
	}

	// ─────────────────────────────────────────────────────────────────
	// REST: the peer must be readable AND filterable
	// ─────────────────────────────────────────────────────────────────

	/** The column reaches the client, or the UI cannot show it. */
	public function test_the_peer_is_serialised_to_the_client(): void {
		$this->record();

		$events = $this->get( array( 'per_page' => 5 ) )->get_data()['events'];

		$this->assertNotEmpty( $events );
		$this->assertArrayHasKey( 'fleet_peer', $events[0] );
		$this->assertSame( 'agency.example', $events[0]['fleet_peer'] );
	}

	/**
	 * `fleet` must be an accepted `connection_type`.
	 *
	 * The parameter is an enum. Adding the type to `ConnectionContext` without
	 * adding it here would record rows the API then refuses to filter by —
	 * a 400, not an empty list, so it fails loudly in the UI and silently in
	 * anything scripted.
	 */
	public function test_connection_type_fleet_is_an_accepted_value(): void {
		$this->record();

		$response = $this->get( array( 'connection_type' => 'fleet', 'per_page' => 5 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['events'] );
	}

	/** And the enum must still reject nonsense. */
	public function test_an_unknown_connection_type_is_still_rejected(): void {
		$this->assertSame( 400, $this->get( array( 'connection_type' => 'banana' ) )->get_status() );
	}

	/** Filtering by peer must actually narrow, not silently pass everything. */
	public function test_filtering_by_peer_narrows_the_result(): void {
		$this->record( array( 'fleet_peer' => 'agency.example' ) );
		$this->record( array( 'fleet_peer' => '', 'connection_type' => 'app-password', 'server_slug' => 'acrossai-mcp-server' ) );

		$filtered = $this->get( array( 'fleet_peer' => 'agency.example', 'per_page' => 50 ) )->get_data()['events'];

		$this->assertCount( 1, $filtered );
		$this->assertSame( 'agency.example', $filtered[0]['fleet_peer'] );

		$unfiltered = $this->get( array( 'per_page' => 50 ) )->get_data()['events'];
		$this->assertCount( 2, $unfiltered, 'Without the filter both rows must be present, or the test above proves nothing.' );
	}

	/** An unknown peer returns nothing rather than everything. */
	public function test_an_unknown_peer_returns_no_rows(): void {
		$this->record();

		$this->assertCount( 0, $this->get( array( 'fleet_peer' => 'nobody.example', 'per_page' => 50 ) )->get_data()['events'] );
	}

	// ─────────────────────────────────────────────────────────────────
	// Filter options, and the cache that outlived the column
	// ─────────────────────────────────────────────────────────────────

	/** The peer is offered as a filter value, so the UI can list hubs. */
	public function test_filter_options_offers_the_peers_seen(): void {
		$this->record( array( 'fleet_peer' => 'agency-one.example' ) );
		$this->record( array( 'fleet_peer' => 'agency-two.example' ) );

		$options = ActivityLogQuery::instance()->filter_options( 30 );

		$this->assertArrayHasKey( 'fleet_peer', $options );
		$this->assertContains( 'agency-one.example', $options['fleet_peer'] );
		$this->assertContains( 'agency-two.example', $options['fleet_peer'] );
		$this->assertContains( 'fleet', $options['connection_type'] );
	}

	/** A site that never joined a fleet gets an empty list, not a stray blank. */
	public function test_a_site_with_no_fleet_traffic_offers_no_peers(): void {
		$this->record( array( 'fleet_peer' => '', 'connection_type' => 'app-password' ) );

		$this->assertSame( array(), ActivityLogQuery::instance()->filter_options( 30 )['fleet_peer'] );
	}

	/**
	 * A payload cached before the column existed must be rebuilt.
	 *
	 * The cached shape is validated rather than merely checked for presence.
	 * A payload written before this upgrade is a well-formed array missing one
	 * key — so without naming the key, it would keep being served for the rest
	 * of its five-minute TTL and the new filter control would be absent for no
	 * visible reason.
	 */
	public function test_a_cached_payload_predating_the_column_is_discarded(): void {
		$this->record();

		set_transient(
			'acrossai_mcp_activity_filters_30',
			array( 'event_type' => array( 'request' ), 'connection_type' => array( 'app-password' ) ),
			300
		);

		$options = ActivityLogQuery::instance()->filter_options( 30 );

		$this->assertArrayHasKey( 'fleet_peer', $options, 'The stale shape must not be served.' );
		$this->assertContains( 'agency.example', $options['fleet_peer'] );
	}

	/** A complete cached payload is still reused — the guard must not defeat the cache. */
	public function test_a_complete_cached_payload_is_still_served(): void {
		$sentinel = array(
			'event_type'      => array( 'request' ),
			'connection_type' => array( 'fleet' ),
			'fleet_peer'      => array( 'from-the-cache.example' ),
		);

		set_transient( 'acrossai_mcp_activity_filters_30', $sentinel, 300 );

		$this->assertSame(
			array( 'from-the-cache.example' ),
			ActivityLogQuery::instance()->filter_options( 30 )['fleet_peer'],
			'A payload carrying every key must be served from cache, or the guard has turned the cache off.'
		);
	}
}
