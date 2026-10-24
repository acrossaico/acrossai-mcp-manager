<?php
/**
 * REST contract for the activity log (Feature 096, T051 + T052).
 *
 * Exercises the routes through the real REST server rather than calling the
 * controller directly — permission callbacks, argument sanitisation and the
 * enum validation only exist on that path.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\REST\ActivityLogController;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use WP_REST_Request;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\REST\ActivityLogController
 */
class RestActivityControllerTest extends \WP_UnitTestCase {

	/** @var int */
	private static $admin_id = 0;

	/** @var int */
	private static $subscriber_id = 0;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function set_up() {
		parent::set_up();

		Table::instance()->maybe_upgrade();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		ActivityLogController::instance()->register_routes();
	}

	/** Dispatch a request and return the response. */
	private function dispatch( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/acrossai-mcp-manager/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/** Seed one request event with one ability child. */
	private function seed_pair(): array {
		$parent = Recorder::record_request(
			array(
				'method'          => 'tools/call',
				'component_name'  => 'toolset/content',
				'invoked_name'    => 'toolset-content',
				'status'          => 'success',
				'duration_ms'     => 42,
				'output_bytes'    => 4096,
				'connection_type' => 'app-password',
				'server_slug'     => 'acrossai-mcp-server',
				'input_preview'   => '{"action":"discover"}',
			)
		);

		$child = Recorder::record_ability(
			array(
				'parent_id'      => $parent,
				'component_name' => 'content/update-post',
				'invoked_via'    => 'mcp',
				'status'         => 'success',
				'output_preview' => '{"ok":true}',
			)
		);

		return array( $parent, $child );
	}

	// --- Authorization ---

	/** S2: every route refuses a user without manage_options. */
	public function test_a_subscriber_is_refused_on_every_route(): void {
		wp_set_current_user( self::$subscriber_id );

		$routes = array(
			array( 'GET', '/activity' ),
			array( 'GET', '/activity/summary' ),
			array( 'GET', '/activity/filters' ),
			array( 'GET', '/activity/1' ),
			array( 'GET', '/activity/1/children' ),
			array( 'DELETE', '/activity' ),
		);

		foreach ( $routes as $route ) {
			$response = $this->dispatch( $route[0], $route[1] );

			$this->assertSame(
				403,
				$response->get_status(),
				sprintf( '%s %s must refuse a subscriber.', $route[0], $route[1] )
			);
		}
	}

	/** And an anonymous visitor, which is the more common case. */
	public function test_an_anonymous_visitor_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', '/activity' );

		$this->assertSame( 401, $response->get_status() );
	}

	// --- Reads ---

	public function test_an_administrator_gets_the_event_list(): void {
		wp_set_current_user( self::$admin_id );
		$this->seed_pair();

		$response = $this->dispatch( 'GET', '/activity' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'events', $data );
		$this->assertCount( 1, $data['events'], 'The default view lists request events only.' );
		$this->assertSame( 'toolset/content', $data['events'][0]['component_name'] );
	}

	/**
	 * The list route must NOT carry previews. A page of 100 rows each holding a
	 * 2 KB preview is 200 KB the screen never renders.
	 */
	public function test_the_list_route_omits_previews_and_the_detail_route_includes_them(): void {
		wp_set_current_user( self::$admin_id );
		list( $parent ) = $this->seed_pair();

		$list = $this->dispatch( 'GET', '/activity' )->get_data();

		$this->assertArrayNotHasKey( 'input_preview', $list['events'][0] );
		$this->assertArrayNotHasKey( 'output_preview', $list['events'][0] );

		$detail = $this->dispatch( 'GET', '/activity/' . $parent )->get_data();

		$this->assertArrayHasKey( 'input_preview', $detail );
		$this->assertSame( '{"action":"discover"}', $detail['input_preview'] );
	}

	public function test_an_unknown_id_is_a_404(): void {
		wp_set_current_user( self::$admin_id );

		$response = $this->dispatch( 'GET', '/activity/999999' );

		$this->assertSame( 404, $response->get_status() );
	}

	/** The parent/child read — what two tables would have needed two controllers for. */
	public function test_children_returns_the_abilities_beneath_a_request(): void {
		wp_set_current_user( self::$admin_id );
		list( $parent ) = $this->seed_pair();

		$data = $this->dispatch( 'GET', '/activity/' . $parent . '/children' )->get_data();

		$this->assertCount( 1, $data['events'] );
		$this->assertSame( 'content/update-post', $data['events'][0]['component_name'] );
		$this->assertSame( $parent, $data['events'][0]['parent_id'] );
	}

	public function test_the_abilities_view_is_a_filter_not_a_separate_endpoint(): void {
		wp_set_current_user( self::$admin_id );
		$this->seed_pair();

		$data = $this->dispatch(
			'GET',
			'/activity',
			array( 'event_type' => ActivityLogConstants::EVENT_ABILITY )
		)->get_data();

		$this->assertCount( 1, $data['events'] );
		$this->assertSame( ActivityLogConstants::EVENT_ABILITY, $data['events'][0]['event_type'] );
	}

	public function test_each_filter_narrows_the_result(): void {
		wp_set_current_user( self::$admin_id );

		Recorder::record_request( array( 'method' => 'tools/call', 'status' => 'success' ) );
		Recorder::record_request( array( 'method' => 'tools/list', 'status' => 'error' ) );

		$all = $this->dispatch( 'GET', '/activity' )->get_data();
		$this->assertCount( 2, $all['events'] );

		$errors = $this->dispatch( 'GET', '/activity', array( 'status' => 'error' ) )->get_data();
		$this->assertCount( 1, $errors['events'] );
		$this->assertSame( 'tools/list', $errors['events'][0]['method'] );
	}

	/** An invalid enum is a 400 naming the parameter, not a silent empty result. */
	public function test_an_invalid_enum_is_rejected(): void {
		wp_set_current_user( self::$admin_id );

		$response = $this->dispatch( 'GET', '/activity', array( 'status' => 'not-a-status' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	// --- Pagination ---

	/**
	 * Keyset and offset must agree. If they disagree, deep pages silently
	 * repeat or skip rows — the failure mode the id tiebreaker exists for.
	 */
	public function test_cursor_pagination_walks_every_row_exactly_once(): void {
		wp_set_current_user( self::$admin_id );

		for ( $i = 0; $i < 7; $i++ ) {
			Recorder::record_request( array( 'method' => 'ping-' . $i, 'request_id' => (string) $i ) );
		}

		$seen   = array();
		$cursor = null;
		$guard  = 0;

		do {
			$params = array( 'per_page' => 3 );

			if ( null !== $cursor ) {
				$params['cursor_timestamp'] = $cursor['timestamp'];
				$params['cursor_id']        = $cursor['id'];
			}

			$page = $this->dispatch( 'GET', '/activity', $params )->get_data();

			foreach ( $page['events'] as $event ) {
				$seen[] = $event['id'];
			}

			$cursor = $page['next_cursor'];
			++$guard;
		} while ( null !== $cursor && $guard < 10 );

		$this->assertCount( 7, $seen, 'Every row must appear exactly once across the pages.' );
		$this->assertSame( $seen, array_unique( $seen ), 'No row may repeat across a page boundary.' );
	}

	/**
	 * Rows sharing a created_at second are the case that breaks naive keyset
	 * pagination. Everything the Recorder writes in one test run shares a
	 * second, so this is the realistic shape, not a contrived one.
	 */
	public function test_rows_in_the_same_second_still_paginate_without_loss(): void {
		wp_set_current_user( self::$admin_id );

		$stamp = current_time( 'mysql', true );

		for ( $i = 0; $i < 5; $i++ ) {
			Recorder::record_request( array( 'method' => 'ping', 'created_at' => $stamp ) );
		}

		$first  = $this->dispatch( 'GET', '/activity', array( 'per_page' => 2 ) )->get_data();
		$cursor = $first['next_cursor'];

		$this->assertNotNull( $cursor, 'A second page must be offered.' );

		$second = $this->dispatch(
			'GET',
			'/activity',
			array(
				'per_page'         => 2,
				'cursor_timestamp' => $cursor['timestamp'],
				'cursor_id'        => $cursor['id'],
			)
		)->get_data();

		$ids = array_merge(
			wp_list_pluck( $first['events'], 'id' ),
			wp_list_pluck( $second['events'], 'id' )
		);

		$this->assertCount( 4, $ids );
		$this->assertSame( $ids, array_unique( $ids ), 'Identical timestamps must not collapse pages.' );
	}

	// --- Summary and filters ---

	public function test_summary_reports_the_four_tiles_and_an_approximate_token_figure(): void {
		wp_set_current_user( self::$admin_id );

		Recorder::record_request( array( 'method' => 'tools/call', 'status' => 'success', 'duration_ms' => 10, 'output_bytes' => 400, 'client_name' => 'Claude' ) );
		Recorder::record_request( array( 'method' => 'tools/call', 'status' => 'error', 'duration_ms' => 30, 'output_bytes' => 400, 'client_name' => 'Claude' ) );

		$data = $this->dispatch( 'GET', '/activity/summary', array( 'days' => 1 ) )->get_data();

		$this->assertSame( 2, $data['requests'] );
		$this->assertSame( 20, $data['avg_duration_ms'] );
		$this->assertSame( 50.0, $data['success_rate'] );
		$this->assertSame( 1, $data['active_clients'], 'Distinct client applications, not request count.' );
		$this->assertSame( 800, $data['context_bytes'] );
		$this->assertSame( 200, $data['context_tokens_approx'], '800 bytes / 4 = 200.' );
	}

	/** FR-022: no token figure is ever presented as exact, and none is stored. */
	public function test_the_token_estimate_is_filterable_and_can_be_declined(): void {
		wp_set_current_user( self::$admin_id );
		Recorder::record_request( array( 'method' => 'tools/call', 'output_bytes' => 400 ) );

		$decline = static fn() => null;
		add_filter( ActivityLogConstants::FILTER_ESTIMATED_TOKENS, $decline );
		$data = $this->dispatch( 'GET', '/activity/summary' )->get_data();
		remove_filter( ActivityLogConstants::FILTER_ESTIMATED_TOKENS, $decline );

		$this->assertNull(
			$data['context_tokens_approx'],
			'Returning null must render a dash rather than a guess.'
		);
	}

	public function test_filters_returns_distinct_values_for_the_controls(): void {
		wp_set_current_user( self::$admin_id );

		Recorder::record_request( array( 'method' => 'tools/call', 'status' => 'success' ) );
		Recorder::record_request( array( 'method' => 'tools/list', 'status' => 'success' ) );

		$data = $this->dispatch( 'GET', '/activity/filters' )->get_data();

		$this->assertArrayHasKey( 'method', $data );
		$this->assertContains( 'tools/call', $data['method'] );
		$this->assertContains( 'tools/list', $data['method'] );
	}

	// --- Purge ---

	/** A24: the purge records itself, and the record survives the purge. */
	public function test_purge_empties_the_log_and_records_that_it_did(): void {
		wp_set_current_user( self::$admin_id );
		$this->seed_pair();

		$response = $this->dispatch( 'DELETE', '/activity' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $data['removed'], 'Both seeded events should be counted.' );

		$remaining = $this->dispatch(
			'GET',
			'/activity',
			array( 'event_type' => ActivityLogConstants::EVENT_REMOVAL )
		)->get_data();

		$this->assertCount( 1, $remaining['events'], 'The removal record must survive as the first entry.' );
		$this->assertSame( 'purge', $remaining['events'][0]['component_name'] );
		$this->assertSame( self::$admin_id, $remaining['events'][0]['user_id'] );
	}

	// --- XSS (T052) ---

	/**
	 * No client-influenced field can carry executable markup out of the API.
	 *
	 * All five originate outside the site, and the screen renders them to a
	 * `manage_options` user — the highest-value XSS target on the install.
	 *
	 * The defence turns out to be deeper than planned. BerlinDB's default
	 * validator for text and string columns is `wp_kses_data`
	 * (`Kern\Column::get_validation_callback()`), so script tags are stripped at
	 * the STORAGE layer, before anything reaches the API or the screen. React
	 * escaping the rendered text is then the second layer rather than the only
	 * one.
	 *
	 * This test therefore asserts the security property — nothing executable
	 * survives — rather than byte-for-byte round-tripping, which would pin an
	 * implementation detail of the vendor's sanitiser.
	 *
	 * @dataProvider client_influenced_fields
	 */
	public function test_no_client_influenced_field_can_carry_executable_markup( string $field ): void {
		wp_set_current_user( self::$admin_id );

		$id = Recorder::record_request(
			array(
				'method' => 'tools/call',
				$field   => '<script>alert("xss")</script>',
			)
		);

		$detail = $this->dispatch( 'GET', '/activity/' . $id )->get_data();
		$value  = (string) $detail[ $field ];

		$this->assertStringNotContainsString(
			'<script',
			$value,
			sprintf( '%s must not carry a script tag out of the API.', $field )
		);
		$this->assertStringNotContainsString( '</script', $value );

		// The inner text survives, which is correct: an operator debugging a
		// misbehaving client needs to see what it actually sent.
		$this->assertStringContainsString(
			'alert',
			$value,
			sprintf( '%s must still show the operator what the client sent.', $field )
		);

		// And the JSON encoding adds no second-order escape hatch.
		$this->assertStringNotContainsString( '<script', (string) wp_json_encode( $detail ) );
	}

	/**
	 * Data provider — annotation, not an attribute: PHPUnit is pinned to ^9.6
	 * because WP's abstract testcase calls APIs removed in 10.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function client_influenced_fields(): array {
		return array(
			'component_name' => array( 'component_name' ),
			'error_message'  => array( 'error_message' ),
			'client_name'    => array( 'client_name' ),
			'user_agent'     => array( 'user_agent' ),
			'invoked_name'   => array( 'invoked_name' ),
		);
	}
}
