<?php
/**
 * The `mcp-manager/*` activity-log abilities and the Toolset that fronts them.
 *
 * The paging cases are the ones worth having. A keyset cursor that is wrong by
 * one row does not fail loudly — it silently repeats or skips events, and the
 * answer still looks like a plausible log. `test_pages_do_not_overlap_or_skip`
 * is what catches that, by reassembling the pages and comparing against an
 * unpaged read of the same rows.
 *
 * PHPUnit is pinned ^9.6 — `@dataProvider`, never `#[DataProvider]`.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\ActivityLog\GetLog;
use AcrossAI_MCP_Manager\Includes\Abilities\ActivityLog\LogFilters;
use AcrossAI_MCP_Manager\Includes\Abilities\ActivityLog\QueryLogs;
use AcrossAI_MCP_Manager\Includes\Abilities\ActivityLog\SummarizeLogs;
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\AbilityGroup;
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\McpManager;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ActivityLogAbilitiesTest extends WP_UnitTestCase {

	/** @var int */
	private $admin_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		Query::flush_cache();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		Query::flush_cache();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Cursor — pure, and the part a paging bug hides in.
	// ------------------------------------------------------------------

	public function test_cursor_round_trips(): void {
		$cursor = QueryLogs::encode_cursor( '2026-11-06 08:00:27', 1522 );

		$this->assertSame( array( '2026-11-06 08:00:27', 1522 ), QueryLogs::decode_cursor( $cursor ) );
	}

	/**
	 * @dataProvider provideBadCursors
	 */
	public function test_a_damaged_cursor_starts_from_the_beginning( string $cursor ): void {
		// Not an error: a cursor is a round-trip token, and failing a read
		// because a model reformatted a string it was told not to touch is
		// worse than starting over.
		$this->assertSame( array( '', 0 ), QueryLogs::decode_cursor( $cursor ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provideBadCursors(): array {
		return array(
			'empty'        => array( '' ),
			'no separator' => array( '2026-11-06 08:00:27' ),
			'no id'        => array( '2026-11-06 08:00:27|' ),
			'zero id'      => array( '2026-11-06 08:00:27|0' ),
			'no timestamp' => array( '|1522' ),
			'nonsense'     => array( 'not-a-cursor' ),
		);
	}

	// ------------------------------------------------------------------
	// Registration and group membership.
	// ------------------------------------------------------------------

	public function test_all_four_abilities_register(): void {
		foreach ( $this->slugs() as $slug ) {
			$this->assertTrue( wp_has_ability( $slug ), "{$slug} is not registered" );
		}
	}

	public function test_every_ability_joins_the_mcp_manager_group(): void {
		$members = AbilityGroup::member_names( McpManager::GROUP );

		foreach ( $this->slugs() as $slug ) {
			$this->assertContains( $slug, $members, "{$slug} is missing from the group its dispatcher serves" );
		}
	}

	public function test_the_dispatcher_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'toolset/mcp-manager' ) );
	}

	public function test_the_log_is_admin_only(): void {
		// The log records who reached this site and what they ran. A logged-in
		// subscriber must not be able to read it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( $this->slugs() as $slug ) {
			$ability = wp_get_ability( $slug );

			$this->assertNotNull( $ability, "{$slug} is not registered" );

			// check_permissions() returns true, false or WP_Error depending on
			// the failure; anything other than true means refused.
			$this->assertNotTrue( $ability->check_permissions(), "{$slug} is readable by a subscriber" );
		}
	}

	// ------------------------------------------------------------------
	// Paging.
	// ------------------------------------------------------------------

	public function test_defaults_are_two_hundred_rows_at_one_hundred_a_page(): void {
		$this->assertSame( 200, QueryLogs::DEFAULT_LIMIT );
		$this->assertSame( 100, QueryLogs::DEFAULT_PER_PAGE );
	}

	public function test_limit_caps_what_one_call_returns(): void {
		$this->seed( 12 );

		$result = QueryLogs::execute( array( 'limit' => 5 ) );

		$this->assertCount( 5, $result['events'] );
		$this->assertSame( 5, $result['count'] );
		$this->assertTrue( $result['has_more'] );
		$this->assertNotNull( $result['next_cursor'] );
	}

	public function test_pages_do_not_overlap_or_skip(): void {
		$this->seed( 10 );

		$seen   = array();
		$cursor = '';

		// Three rows at a time, following the cursor — the shape a caller uses.
		for ( $i = 0; $i < 10; $i++ ) {
			$page = QueryLogs::execute(
				array_filter(
					array(
						'limit'  => 3,
						'cursor' => $cursor,
					)
				)
			);

			foreach ( $page['events'] as $event ) {
				$seen[] = (int) $event['id'];
			}

			if ( ! $page['has_more'] ) {
				break;
			}

			$cursor = (string) $page['next_cursor'];
		}

		$this->assertCount( 10, $seen, 'paging returned a different number of rows than exist' );
		$this->assertSame( $seen, array_unique( $seen ), 'a row was returned on more than one page' );

		// And the same rows an unpaged read sees, in the same order.
		$unpaged = QueryLogs::execute( array( 'limit' => 50 ) );

		$this->assertSame( array_column( $unpaged['events'], 'id' ), $seen );
	}

	public function test_exhausting_the_log_reports_no_further_page(): void {
		$this->seed( 3 );

		$result = QueryLogs::execute( array( 'limit' => 50 ) );

		$this->assertSame( 3, $result['count'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertNull( $result['next_cursor'] );
	}

	public function test_the_note_tells_the_caller_to_ask_before_paging_on(): void {
		$this->seed( 6 );

		$result = QueryLogs::execute( array( 'limit' => 2 ) );

		// The wording matters: "there are more" invites a model to loop and
		// spend the operator's context unasked, which is what paging exists to
		// prevent.
		$this->assertTrue( $result['has_more'] );
		$this->assertStringContainsString( 'ask', strtolower( (string) $result['note'] ) );
	}

	// ------------------------------------------------------------------
	// Filtering.
	// ------------------------------------------------------------------

	public function test_server_id_scopes_to_one_server(): void {
		$this->seed( 3, array( 'server_id' => 11 ) );
		$this->seed( 4, array( 'server_id' => 22 ) );

		$one = QueryLogs::execute( array( 'server_id' => 22 ) );

		$this->assertSame( 4, $one['count'] );

		foreach ( $one['events'] as $event ) {
			$this->assertSame( 22, $event['server_id'] );
		}
	}

	public function test_omitting_server_id_reads_every_server(): void {
		$this->seed( 3, array( 'server_id' => 11 ) );
		$this->seed( 4, array( 'server_id' => 22 ) );

		$this->assertSame( 7, QueryLogs::execute()['count'] );
	}

	public function test_a_filter_matching_nothing_returns_an_empty_page(): void {
		$this->seed( 3, array( 'server_id' => 11 ) );

		$result = QueryLogs::execute( array( 'server_id' => 999 ) );

		$this->assertSame( 0, $result['count'] );
		$this->assertSame( array(), $result['events'] );
		$this->assertFalse( $result['has_more'] );
	}

	public function test_status_filters_to_failures(): void {
		$this->seed( 2, array( 'status' => 'success' ) );
		$this->seed( 3, array( 'status' => 'error' ) );

		$this->assertSame( 3, QueryLogs::execute( array( 'status' => 'error' ) )['count'] );
	}

	// ------------------------------------------------------------------
	// Row shaping.
	// ------------------------------------------------------------------

	public function test_previews_are_off_by_default_and_on_when_asked(): void {
		$row                 = new Row( array() );
		$row->input_preview  = 'in';
		$row->output_preview = 'out';

		$this->assertArrayNotHasKey( 'input_preview', QueryLogs::shape( $row ) );
		$this->assertArrayHasKey( 'input_preview', QueryLogs::shape( $row, true ) );
	}

	public function test_byte_counts_are_always_present(): void {
		// So a caller can see that a call returned a megabyte without being
		// handed the megabyte.
		$row               = new Row( array() );
		$row->output_bytes = 1048576;

		$shaped = QueryLogs::shape( $row );

		$this->assertSame( 1048576, $shaped['output_bytes'] );
	}

	// ------------------------------------------------------------------
	// get-log.
	// ------------------------------------------------------------------

	public function test_get_log_returns_a_request_with_its_children(): void {
		$parent = $this->insert( array( 'event_type' => 'request' ) );

		$this->insert(
			array(
				'event_type' => 'ability_run',
				'parent_id'  => $parent,
			)
		);
		$this->insert(
			array(
				'event_type' => 'ability_run',
				'parent_id'  => $parent,
			)
		);

		$result = GetLog::execute( array( 'id' => $parent ) );

		$this->assertSame( $parent, $result['event']['id'] );
		$this->assertSame( 2, $result['child_count'] );
		$this->assertCount( 2, $result['children'] );
		$this->assertNull( $result['parent'] );
	}

	public function test_get_log_returns_a_child_with_its_parent(): void {
		$parent = $this->insert( array( 'event_type' => 'request' ) );
		$child  = $this->insert(
			array(
				'event_type' => 'ability_run',
				'parent_id'  => $parent,
			)
		);

		$result = GetLog::execute( array( 'id' => $child ) );

		$this->assertSame( $child, $result['event']['id'] );
		$this->assertNotNull( $result['parent'] );
		$this->assertSame( $parent, $result['parent']['id'] );
	}

	public function test_get_log_errors_clearly_on_a_missing_id(): void {
		$result = GetLog::execute( array( 'id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'acrossai_mcp_log_not_found', $result->get_error_code() );
	}

	public function test_get_log_errors_without_an_id(): void {
		$this->assertWPError( GetLog::execute( array() ) );
	}

	// ------------------------------------------------------------------
	// The two summary-shaped abilities.
	// ------------------------------------------------------------------

	public function test_summarize_returns_period_totals(): void {
		$this->seed( 4 );

		$result = SummarizeLogs::execute( array( 'days' => 7 ) );

		$this->assertSame( 7, $result['days'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'requests', $result['summary'] );
	}

	public function test_filters_lists_the_servers_on_this_site(): void {
		$result = LogFilters::execute( array() );

		$this->assertIsArray( $result['filters'] );
		$this->assertIsArray( $result['servers'] );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * @return string[]
	 */
	private function slugs(): array {
		return array( QueryLogs::SLUG, GetLog::SLUG, LogFilters::SLUG, SummarizeLogs::SLUG );
	}

	/**
	 * Insert N events, newest last.
	 *
	 * @param int                  $count    How many.
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return void
	 */
	private function seed( int $count, array $overrides = array() ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->insert( $overrides );
		}
	}

	/**
	 * Insert one event directly, returning its id.
	 *
	 * Written straight to the table rather than through `Recorder`: these tests
	 * are about reading, and the recorder would drag request context in that
	 * has nothing to do with what is being asserted.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return int
	 */
	private function insert( array $overrides = array() ): int {
		global $wpdb;

		static $second = 0;

		++$second;

		$wpdb->insert(
			$wpdb->prefix . ActivityLogConstants::TABLE,
			array_merge(
				array(
					'event_type'      => 'request',
					'event_source'    => 'mcp',
					'server_id'       => 1,
					'server_slug'     => 'test-server',
					'connection_type' => 'connector',
					'status'          => 'success',
					'method'          => 'tools/call',
					'created_at'      => gmdate( 'Y-m-d H:i:s', time() - 3600 + $second ),
				),
				$overrides
			)
		);

		Query::flush_cache();

		return (int) $wpdb->insert_id;
	}
}
