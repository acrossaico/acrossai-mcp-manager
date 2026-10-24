<?php
/**
 * Ability capture contract (Feature 096, T066 + T067).
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Abilities\AbilityActivityRecorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Observability\CaptureContext;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Abilities\AbilityActivityRecorder
 * @covers \AcrossAI_MCP_Manager\Includes\Observability\CaptureContext
 */
class AbilityRecorderTest extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Table::instance()->maybe_upgrade();
		CaptureContext::instance()->clear();
		AbilityActivityRecorder::instance()->clear();
		update_option( ActivityLogConstants::OPTION_ABILITY_LOGGING, true );
	}

	public function tear_down() {
		delete_option( ActivityLogConstants::OPTION_ABILITY_LOGGING );
		CaptureContext::instance()->clear();
		AbilityActivityRecorder::instance()->clear();
		parent::tear_down();
	}

	private function newest(): ?Row {
		$rows = Query::instance()->query(
			array(
				'number'  => 1,
				'orderby' => 'id',
				'order'   => 'DESC',
			)
		);

		return isset( $rows[0] ) && $rows[0] instanceof Row ? $rows[0] : null;
	}

	// --- The bracket ---

	/**
	 * A thrown ability must still disarm.
	 *
	 * This is the guarantee Feature 097 is built on. Without the `finally`,
	 * every subsequent write in the request is attributed to an ability that
	 * already ended — and 097's change recorder inherits that attribution.
	 */
	public function test_a_thrown_ability_still_disarms_the_context(): void {
		$context = CaptureContext::instance();

		$this->assertFalse( $context->is_armed() );

		try {
			$context->around(
				'content/update-post',
				static function () {
					throw new \RuntimeException( 'deliberate' );
				}
			);
			$this->fail( 'The exception should have propagated to the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'deliberate', $e->getMessage() );
		}

		$this->assertFalse(
			$context->is_armed(),
			'finally must disarm even when the ability throws.'
		);
		$this->assertSame( '', $context->current_ability() );
	}

	public function test_around_returns_the_callables_value_untouched(): void {
		$expected = array( 'ok' => true );

		$actual = CaptureContext::instance()->around( 'content/read', static fn() => $expected );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Nesting: a toolset dispatcher calling a member ability is the common case.
	 * A boolean flag would disarm on the inner completion while the outer was
	 * still running, so depth is counted.
	 */
	public function test_nested_abilities_do_not_disarm_the_outer_one_early(): void {
		$context = CaptureContext::instance();
		$seen    = array();

		$context->around(
			'toolset/content',
			static function () use ( $context, &$seen ) {
				$seen['outer_depth']   = $context->depth();
				$seen['outer_current'] = $context->current_ability();

				$context->around(
					'content/update-post',
					static function () use ( $context, &$seen ) {
						$seen['inner_depth']   = $context->depth();
						$seen['inner_current'] = $context->current_ability();
					}
				);

				$seen['after_inner_depth']   = $context->depth();
				$seen['after_inner_current'] = $context->current_ability();
			}
		);

		$this->assertSame( 1, $seen['outer_depth'] );
		$this->assertSame( 'toolset/content', $seen['outer_current'] );
		$this->assertSame( 2, $seen['inner_depth'] );
		$this->assertSame( 'content/update-post', $seen['inner_current'] );
		$this->assertSame( 1, $seen['after_inner_depth'], 'The outer ability is still armed.' );
		$this->assertSame( 'toolset/content', $seen['after_inner_current'] );
		$this->assertFalse( $context->is_armed(), 'Both levels disarmed on completion.' );
	}

	/**
	 * T067 — the contract Feature 097 depends on.
	 *
	 * `CaptureContext` must be armable and readable from OUTSIDE
	 * `AbilityActivityRecorder`. If it were a private field of the recorder,
	 * 097 would have to re-bracket the same two methods, and two brackets
	 * around one contract is where drift starts.
	 */
	public function test_the_context_is_usable_from_outside_the_recorder(): void {
		$context = CaptureContext::instance();

		// A hypothetical second consumer — exactly what 097 will be.
		$observed = null;

		$context->set_activity_id( 4242 );

		$context->around(
			'content/update-post',
			static function () use ( $context, &$observed ) {
				$observed = array(
					'armed'       => $context->is_armed(),
					'ability'     => $context->current_ability(),
					'activity_id' => $context->activity_id(),
				);
			}
		);

		$this->assertTrue( $observed['armed'] );
		$this->assertSame( 'content/update-post', $observed['ability'] );
		$this->assertSame(
			4242,
			$observed['activity_id'],
			'097 reads this to attach its change rows to the same call.'
		);
	}

	/** A17 stage 3: nothing survives into the next request. */
	public function test_shutdown_clears_the_context(): void {
		$context = CaptureContext::instance();

		$context->set_activity_id( 99 );
		$context->arm( 'content/update-post' );

		$context->clear();

		$this->assertFalse( $context->is_armed() );
		$this->assertSame( 0, $context->activity_id() );
	}

	// --- Adoption: linking children to the request that ran them (#183) ---

	/**
	 * The real ordering: children are written BEFORE the parent exists.
	 *
	 * This is the case that shipped broken, and the reason `set_activity_id()`
	 * alone could never have fixed it. Every other test in this file sets the
	 * parent id up front, which quietly assumes the parent already exists —
	 * in a real request it does not, because the request row is written at the
	 * end once its duration is known. So here nothing is pre-set: the
	 * abilities run first, the request event is recorded afterwards, and the
	 * link has to be made retroactively or not at all.
	 */
	public function test_abilities_are_adopted_by_a_request_recorded_after_them(): void {
		$context = CaptureContext::instance();

		// No activity id, exactly as in flight: the parent does not exist yet.
		$this->assertSame( 0, $context->activity_id() );

		AbilityActivityRecorder::instance()->record_bracketed( 'content/create-post', 'success', array(), 5.0 );
		AbilityActivityRecorder::instance()->record_bracketed( 'toolset/content', 'success', array(), 25.0 );

		$first  = $this->newest();
		$this->assertSame( 0, (int) $first->parent_id, 'A child is written unlinked — it has nothing to link to yet.' );
		$this->assertSame( 2, $context->pending_children(), 'Both children are waiting for a parent.' );

		// Now the request event lands, as it does at the end of a dispatch.
		$parent   = Recorder::record_request( array( 'method' => 'tools/call' ) );
		$children = $context->take_children();
		$linked   = Query::instance()->adopt_children( $parent, $children );

		$this->assertSame( 2, $linked );
		$this->assertSame( 2, Query::instance()->count_children( $parent ) );

		foreach ( Query::instance()->children( $parent ) as $child ) {
			$this->assertSame( $parent, (int) $child->parent_id );
			// Corrected in the same pass: derived from parent_id at write time,
			// which was 0, so it landed as 'rest'.
			$this->assertSame( 'mcp', $child->invoked_via );
		}
	}

	/** Taking the children clears them, so a second request cannot re-adopt. */
	public function test_children_are_adopted_once_and_only_once(): void {
		$context = CaptureContext::instance();

		AbilityActivityRecorder::instance()->record_bracketed( 'content/create-post', 'success', array(), 5.0 );

		$first_parent = Recorder::record_request( array( 'method' => 'tools/call' ) );
		$this->assertSame( 1, Query::instance()->adopt_children( $first_parent, $context->take_children() ) );

		// A second request event in the same cycle must find nothing waiting.
		$this->assertSame( 0, $context->pending_children() );

		$second_parent = Recorder::record_request( array( 'method' => 'tools/call' ) );
		$this->assertSame( 0, Query::instance()->adopt_children( $second_parent, $context->take_children() ) );
		$this->assertSame( 1, Query::instance()->count_children( $first_parent ), 'The first request keeps its child.' );
		$this->assertSame( 0, Query::instance()->count_children( $second_parent ), 'The second adopts nothing.' );
	}

	/**
	 * The request lifecycle clears pending children.
	 *
	 * A17 stage 3. Without this a request that recorded abilities but never
	 * emitted a request event — an abandoned dispatch, a fatal — would leave
	 * its ids behind for the NEXT request to adopt, attributing one caller's
	 * abilities to another's call.
	 */
	public function test_pending_children_do_not_survive_the_request(): void {
		$context = CaptureContext::instance();

		AbilityActivityRecorder::instance()->record_bracketed( 'content/create-post', 'success', array(), 5.0 );
		$this->assertSame( 1, $context->pending_children() );

		$context->clear();

		$this->assertSame( 0, $context->pending_children(), 'Stage 3 must drop pending children with the rest of the state.' );
	}

	/** Adoption is a no-op when there is no parent, and never self-parents. */
	public function test_adoption_refuses_a_missing_parent_or_a_cycle(): void {
		$id = Recorder::record_request( array( 'method' => 'tools/call' ) );

		$this->assertSame( 0, Query::instance()->adopt_children( 0, array( $id ) ), 'No parent, nothing linked.' );
		$this->assertSame( 0, Query::instance()->adopt_children( $id, array() ), 'No children, nothing linked.' );
		$this->assertSame( 0, Query::instance()->adopt_children( $id, array( $id ) ), 'A row must never become its own parent.' );

		$row = Query::instance()->get_item( $id );
		$this->assertSame( 0, (int) $row->parent_id );
	}

	// --- Recording through the bracket ---

	public function test_a_bracketed_success_records_one_row_with_its_parent(): void {
		$parent = Recorder::record_request( array( 'method' => 'tools/call' ) );
		CaptureContext::instance()->set_activity_id( $parent );

		AbilityActivityRecorder::instance()->record_bracketed(
			'content/update-post',
			'success',
			array(
				'input'  => array( 'id' => 7 ),
				'output' => array( 'ok' => true ),
			),
			12.4
		);

		$row = $this->newest();

		$this->assertSame( ActivityLogConstants::EVENT_ABILITY, $row->event_type );
		$this->assertSame( $parent, $row->parent_id );
		$this->assertSame( 'content/update-post', $row->component_name );
		$this->assertSame( 'mcp', $row->invoked_via );
		$this->assertSame( 'success', $row->status );
		$this->assertSame( 12, $row->duration_ms );
		$this->assertNotNull( $row->output_preview, 'Unlike a request row, this one CAN carry the result.' );
		$this->assertSame( 1, Query::instance()->count_children( $parent ) );
	}

	/**
	 * The WP_Error case — the `wp_after_execute_ability` early-return trap.
	 *
	 * Verified in core at class-wp-ability.php:634: a WP_Error returns before
	 * the end hook fires, so on WordPress 6.9 the bracket is the ONLY path that
	 * records this. A log that recorded only successes would read as "nothing
	 * went wrong", which is worse than no log.
	 */
	public function test_an_error_result_records_status_error(): void {
		AbilityActivityRecorder::instance()->record_bracketed(
			'content/update-post',
			'error',
			array(
				'error_code'    => 'post_not_found',
				'error_message' => 'No post with that id.',
			),
			8.0
		);

		$row = $this->newest();

		$this->assertSame( 'error', $row->status );
		$this->assertSame( 'post_not_found', $row->error_code );
		$this->assertStringContainsString( 'No post', $row->error_message );
	}

	/** An ability outside MCP has no parent, and that is correct. */
	public function test_a_non_mcp_ability_records_zero_parent(): void {
		AbilityActivityRecorder::instance()->on_before_execute( 'content/read', array() );
		AbilityActivityRecorder::instance()->on_after_execute( 'content/read', array(), array( 'ok' => true ) );

		$row = $this->newest();

		$this->assertSame( 0, $row->parent_id );
		$this->assertNotSame( 'mcp', $row->invoked_via, 'Nothing armed the context, so this was not MCP.' );
	}

	/**
	 * The re-entry guard. On WordPress 7.1+ both capture paths observe the same
	 * MCP-originated call; exactly one row must result.
	 */
	public function test_both_capture_paths_seeing_one_call_produce_exactly_one_row(): void {
		$parent = Recorder::record_request( array( 'method' => 'tools/call' ) );
		CaptureContext::instance()->set_activity_id( $parent );

		$recorder = AbilityActivityRecorder::instance();

		// Path 2 (the bracket) then path 1 (core's end hook), same call.
		$recorder->record_bracketed( 'content/update-post', 'success', array( 'output' => array() ), 5.0 );
		$recorder->on_after_execute( 'content/update-post', array(), array( 'ok' => true ) );

		$this->assertSame(
			1,
			Query::instance()->count_children( $parent ),
			'Two capture paths, one execution, one row.'
		);
	}

	/** The same ability under two different dispatchers is two real events. */
	public function test_the_guard_is_keyed_on_parent_so_repeats_under_different_calls_both_record(): void {
		$recorder = AbilityActivityRecorder::instance();

		$first = Recorder::record_request( array( 'method' => 'tools/call', 'request_id' => '1' ) );
		CaptureContext::instance()->set_activity_id( $first );
		$recorder->record_bracketed( 'content/read', 'success', array(), 1.0 );

		$second = Recorder::record_request( array( 'method' => 'tools/call', 'request_id' => '2' ) );
		CaptureContext::instance()->set_activity_id( $second );
		$recorder->record_bracketed( 'content/read', 'success', array(), 1.0 );

		$this->assertSame( 1, Query::instance()->count_children( $first ) );
		$this->assertSame( 1, Query::instance()->count_children( $second ) );
	}

	// --- Toggle and safety ---

	public function test_the_toggle_off_records_nothing_and_leaves_request_logging_alone(): void {
		delete_option( ActivityLogConstants::OPTION_ABILITY_LOGGING );

		$request = Recorder::record_request( array( 'method' => 'tools/call' ) );

		AbilityActivityRecorder::instance()->record_bracketed( 'content/read', 'success', array(), 1.0 );

		$this->assertSame( 0, Query::instance()->count_children( $request ) );
		$this->assertInstanceOf(
			Row::class,
			Query::instance()->get_item( $request ),
			'Request logging is a separate switch and must be unaffected.'
		);
	}

	/**
	 * FR-013. This runs inside the execution path of every ability on the site,
	 * so a capture bug must never break the ability it is observing.
	 */
	public function test_a_storage_failure_does_not_propagate(): void {
		global $wpdb;

		$break = static function ( $query ) {
			return false !== stripos( (string) $query, 'acrossai_mcp_activity_log' )
				? 'SELECT not_valid FROM nowhere'
				: $query;
		};

		add_filter( 'query', $break );
		$suppressed = $wpdb->suppress_errors( true );

		AbilityActivityRecorder::instance()->record_bracketed( 'content/read', 'success', array(), 1.0 );

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );

		$this->assertTrue( true, 'No exception escaped.' );
	}

	/** Malformed hook arguments must not fatal. */
	public function test_malformed_hook_arguments_are_ignored(): void {
		$recorder = AbilityActivityRecorder::instance();

		$recorder->on_before_execute( array( 'not', 'a', 'string' ), null );
		$recorder->on_after_execute( null, null, null );

		$this->assertNull( $this->newest(), 'Nothing recorded, nothing thrown.' );
	}

	/** The 7.1 quartet is feature-detected, never required. */
	public function test_rich_hook_detection_matches_this_wordpress_version(): void {
		global $wp_version;

		$this->assertSame(
			version_compare( (string) $wp_version, '7.1', '>=' ),
			AbilityActivityRecorder::has_rich_hooks(),
			'Detection must track the running version, not a hardcoded answer.'
		);
	}

	/** The 7.1 filters must return their input untouched. */
	public function test_the_rich_hook_filters_pass_their_value_through(): void {
		$recorder = AbilityActivityRecorder::instance();

		$result = array( 'ok' => true );
		$this->assertSame( $result, $recorder->on_execute_result( $result, 'content/read', array() ) );

		$denied = false;
		$this->assertSame( $denied, $recorder->on_permission_result( $denied, 'content/read', array() ) );

		$valid = true;
		$this->assertSame( $valid, $recorder->on_validate_input( $valid, 'content/read', array() ) );
	}

	/** A denial is a row worth having: "not called" and "called and refused" differ. */
	public function test_a_permission_denial_records_status_denied(): void {
		AbilityActivityRecorder::instance()->on_permission_result( false, 'content/update-post', array() );

		$row = $this->newest();

		$this->assertInstanceOf( Row::class, $row );
		$this->assertSame( 'denied', $row->status );
		$this->assertSame( 'content/update-post', $row->component_name );
	}

	/** Invalid input is its own outcome, distinct from a runtime error. */
	public function test_invalid_input_records_its_own_status(): void {
		$error = new \WP_Error( 'rest_invalid_param', 'action is not a valid property of Object.' );

		AbilityActivityRecorder::instance()->on_validate_input( $error, 'toolset/server-guide', array() );

		$row = $this->newest();

		$this->assertSame( 'invalid_input', $row->status );
		$this->assertSame( 'rest_invalid_param', $row->error_code );
	}
}
