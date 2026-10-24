<?php
/**
 * Capture contract for the observability handler (Feature 096, T050).
 *
 * The handler is the heart of User Story 1: it is what turns the adapter's
 * discarded measurements into rows. Needs WordPress for the current user,
 * options and filters.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Abilities\AbilityActivityRecorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Observability\ActivityObservabilityHandler;
use AcrossAI_MCP_Manager\Includes\Observability\AuthContextHolder;
use AcrossAI_MCP_Manager\Includes\Observability\CaptureContext;
use AcrossAI_MCP_Manager\Includes\Observability\ConnectionContext;
use AcrossAI_MCP_Manager\Includes\Observability\HandlerResolver;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Observability\ActivityObservabilityHandler
 * @covers \AcrossAI_MCP_Manager\Includes\Observability\ConnectionContext
 * @covers \AcrossAI_MCP_Manager\Includes\Observability\HandlerResolver
 */
class ObservabilityHandlerTest extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Table::instance()->maybe_upgrade();
		AuthContextHolder::instance()->clear();
		ConnectionContext::flush_cache();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
	}

	public function tear_down() {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		AuthContextHolder::instance()->clear();
		parent::tear_down();
	}

	/** Tags shaped like the ones live traffic actually produced. */
	private function tags( array $overrides = array() ): array {
		return array_merge(
			array(
				'method'         => 'tools/call',
				'transport'      => 'HTTP',
				'server_id'      => 'acrossai-mcp-server',
				'request_id'     => '3',
				'session_id'     => 'bb9526ea-8c54-4935-a818-d3b4c5a8ade4',
				'component_type' => 'tool',
				'tool_name'      => 'toolset-server-guide',
				'ability_name'   => 'toolset/server-guide',
				'source'         => 'ability',
				'status'         => 'success',
			),
			$overrides
		);
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

	// --- The basics ---

	public function test_records_one_request_row_with_no_parent(): void {
		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $this->tags(), 35.6 );

		$row = $this->newest();

		$this->assertInstanceOf( Row::class, $row );
		$this->assertSame( ActivityLogConstants::EVENT_REQUEST, $row->event_type );
		$this->assertSame( 0, $row->parent_id );
		$this->assertSame( 'tools/call', $row->method );
		$this->assertSame( 36, $row->duration_ms, 'Float milliseconds are rounded, not truncated.' );
		$this->assertSame( 'HTTP', $row->transport );
	}

	/** D-11: the adapter's own `source` tag must never reach `event_source`. */
	public function test_the_adapter_source_tag_does_not_leak_into_event_source(): void {
		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $this->tags(), 1.0 );

		$row = $this->newest();

		$this->assertSame(
			ActivityLogConstants::SOURCE_MCP,
			$row->event_source,
			'The adapter sends source=ability; event_source must stay mcp (D-11).'
		);
	}

	/** D-10: the canonical slug is stored, with the client's spelling beside it. */
	public function test_stores_the_joinable_ability_name_and_the_clients_spelling(): void {
		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $this->tags(), 1.0 );

		$row = $this->newest();

		$this->assertSame(
			'toolset/server-guide',
			$row->component_name,
			'component_name must be the slug that joins to the abilities registry (D-10).'
		);
		$this->assertSame(
			'toolset-server-guide',
			$row->invoked_name,
			'The sanitised name the client sees must be recoverable.'
		);
	}

	/** When the two names agree, invoked_name stays empty rather than duplicating. */
	public function test_invoked_name_is_empty_when_the_names_agree(): void {
		$tags = $this->tags(
			array(
				'tool_name'    => 'toolset/server-guide',
				'ability_name' => 'toolset/server-guide',
			)
		);

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $tags, 1.0 );

		$this->assertSame( '', $this->newest()->invoked_name );
	}

	/**
	 * Open Decision 3, confirmed on live traffic: on `initialize` the
	 * `session_id` tag is absent and the id arrives as `new_session_id`. Without
	 * the coalesce, the one event carrying clientInfo is the one event that
	 * cannot be tied to the session it opened.
	 */
	public function test_initialize_takes_its_session_id_from_new_session_id(): void {
		$tags = array(
			'method'         => 'initialize',
			'server_id'      => 'acrossai-mcp-server',
			'new_session_id' => 'fresh-session-id',
			'status'         => 'success',
			'params'         => array( 'protocolVersion' => '2025-06-18' ),
		);

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $tags, 5.7 );

		$row = $this->newest();

		$this->assertSame( 'fresh-session-id', $row->session_id );
		$this->assertSame( '2025-06-18', $row->protocol_version );
	}

	/** D-12: a validation failure carries prose, not a code. */
	public function test_an_error_without_an_error_code_still_records_the_reason(): void {
		$tags = $this->tags(
			array(
				'status'         => 'error',
				'failure_reason' => 'action is not a valid property of Object.',
			)
		);

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $tags, 35.0 );

		$row = $this->newest();

		$this->assertSame( 'error', $row->status );
		$this->assertSame( '', $row->error_code, 'There is no error_code tag (D-12).' );
		$this->assertStringContainsString( 'not a valid property', $row->error_message );
	}

	// --- Events we ignore ---

	/**
	 * `mcp.server.created` fires twice per HTTP request on this install (once
	 * per enabled server). Recording it would more than double every request's
	 * row count for no diagnostic gain.
	 */
	public function test_ignores_every_event_except_mcp_request(): void {
		$handler = new ActivityObservabilityHandler();

		$handler->record_event( 'mcp.server.created', array( 'server_id' => 'acrossai-mcp-server' ), null );
		$handler->record_event( 'mcp.component.registration', array(), null );

		$this->assertNull( $this->newest(), 'Only mcp.request is recorded.' );
	}

	/** `ping` is most of the volume and none of the value — and it is filterable. */
	public function test_ping_is_excluded_by_default_and_the_denylist_is_filterable(): void {
		$handler = new ActivityObservabilityHandler();

		$handler->record_event( 'mcp.request', $this->tags( array( 'method' => 'ping' ) ), 1.0 );
		$this->assertNull( $this->newest(), 'ping is excluded by default.' );

		$allow_all = static fn() => array();
		add_filter( 'acrossai_mcp_activity_excluded_methods', $allow_all );
		$handler->record_event( 'mcp.request', $this->tags( array( 'method' => 'ping' ) ), 1.0 );
		remove_filter( 'acrossai_mcp_activity_excluded_methods', $allow_all );

		$this->assertSame( 'ping', $this->newest()->method, 'An operator can turn ping back on.' );
	}

	// --- connection_type, all five branches ---

	public function test_connection_type_ai_connector_when_the_client_has_a_connector_profile(): void {
		AuthContextHolder::instance()->capture_oauth_context( 'claude', 'Claude Desktop' );

		$this->assertSame( 'ai-connector', ConnectionContext::resolve_type() );

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $this->tags(), 1.0 );

		$row = $this->newest();

		$this->assertSame( 'ai-connector', $row->connection_type );
		$this->assertSame( 'claude', $row->connector_slug );
	}

	/** A bare DCR client is `oauth`, not `ai-connector` and not `unknown`. */
	public function test_connection_type_oauth_for_a_bare_client(): void {
		AuthContextHolder::instance()->capture_oauth_context( '', '' );

		$this->assertSame( 'oauth', ConnectionContext::resolve_type() );
	}

	public function test_connection_type_app_password(): void {
		$GLOBALS['wp_rest_application_password_uuid'] = 'some-uuid';

		$this->assertSame( 'app-password', ConnectionContext::resolve_type() );
	}

	public function test_connection_type_browser_for_a_logged_in_cookie_session(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 'browser', ConnectionContext::resolve_type() );
	}

	public function test_connection_type_unknown_when_nothing_matched(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 'unknown', ConnectionContext::resolve_type() );
	}

	/** OAuth is checked first: an app-password global must not mask a bearer. */
	public function test_oauth_wins_over_a_stale_application_password_global(): void {
		AuthContextHolder::instance()->capture_oauth_context( 'chatgpt', 'ChatGPT' );
		$GLOBALS['wp_rest_application_password_uuid'] = 'some-uuid';

		$this->assertSame( 'ai-connector', ConnectionContext::resolve_type() );
	}

	// --- FR-013: never throw ---

	/**
	 * The adapter calls this on all four exit paths of every dispatch. A throw
	 * here surfaces as a broken tool call to the AI client, which is the one
	 * outcome FR-013 forbids absolutely.
	 */
	public function test_a_storage_failure_does_not_propagate(): void {
		$break = static function ( $query ) {
			return false !== stripos( (string) $query, 'acrossai_mcp_activity_log' )
				? 'SELECT not_valid_sql FROM nowhere'
				: $query;
		};

		global $wpdb;

		add_filter( 'query', $break );
		$suppressed = $wpdb->suppress_errors( true );

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $this->tags(), 1.0 );

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );

		// Reaching this line at all is the assertion: no exception escaped.
		$this->assertTrue( true );
	}

	/** Malformed tags must degrade a column to empty, never fatal. */
	public function test_malformed_tags_degrade_rather_than_fatal(): void {
		$tags = array(
			'method'    => array( 'not', 'a', 'string' ),
			'server_id' => null,
			'status'    => new \stdClass(),
		);

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $tags, null );

		$row = $this->newest();

		$this->assertInstanceOf( Row::class, $row, 'A row is still written.' );
		$this->assertSame( '', $row->method );
		$this->assertNull( $row->duration_ms );
	}

	// --- #183: the handler adopts the abilities its request ran ---

	/**
	 * Recording a request event links the abilities that ran during it.
	 *
	 * The WIRING test, and the one that matters. `AbilityRecorderTest` proves
	 * `adopt_children()` works when called; this proves something calls it.
	 * The original bug was precisely that distinction — `set_activity_id()`
	 * existed, was correct, was covered by six unit tests, and had no
	 * production caller, so the suite stayed green while no real request ever
	 * linked anything.
	 *
	 * Nothing here pre-sets a parent id or calls the adoption directly: the
	 * abilities run, then the handler receives `mcp.request` the way the
	 * adapter delivers it, and the link must exist afterwards.
	 */
	public function test_recording_a_request_links_the_abilities_it_ran(): void {
		update_option( ActivityLogConstants::OPTION_ABILITY_LOGGING, true );

		CaptureContext::instance()->clear();
		AbilityActivityRecorder::instance()->clear();

		// Two abilities run while the request is in flight.
		AbilityActivityRecorder::instance()->record_bracketed( 'content/create-post', 'success', array(), 5.0 );
		AbilityActivityRecorder::instance()->record_bracketed( 'toolset/content', 'success', array(), 25.0 );

		$this->assertSame( 2, CaptureContext::instance()->pending_children() );

		// The adapter delivers the request event at the end of the dispatch.
		( new ActivityObservabilityHandler() )->record_event(
			'mcp.request',
			array(
				'method'    => 'tools/call',
				'tool_name' => 'toolset/content',
				'status'    => 'success',
			),
			177.0
		);

		$request = $this->newest();

		$this->assertSame( ActivityLogConstants::EVENT_REQUEST, $request->event_type );
		$this->assertSame(
			2,
			Query::instance()->count_children( (int) $request->id ),
			'The request event must adopt the abilities recorded during it.'
		);

		foreach ( Query::instance()->children( (int) $request->id ) as $child ) {
			$this->assertSame( (int) $request->id, (int) $child->parent_id );
			$this->assertSame( 'mcp', $child->invoked_via );
		}

		$this->assertSame(
			0,
			CaptureContext::instance()->pending_children(),
			'Adopted children must be consumed, not left for the next request.'
		);

		delete_option( ActivityLogConstants::OPTION_ABILITY_LOGGING );
	}

	/** An MCP request that ran no abilities adopts nothing and still records. */
	public function test_a_request_with_no_abilities_records_normally(): void {
		CaptureContext::instance()->clear();

		( new ActivityObservabilityHandler() )->record_event(
			'mcp.request',
			array(
				'method' => 'tools/list',
				'status' => 'success',
			),
			8.0
		);

		$request = $this->newest();

		$this->assertSame( ActivityLogConstants::EVENT_REQUEST, $request->event_type );
		$this->assertSame( 0, Query::instance()->count_children( (int) $request->id ) );
	}

	// --- FR-029: off means not reached ---

	/**
	 * While request logging is off the adapter keeps the Null handler, so the
	 * recording path is never entered — not entered and returning early.
	 *
	 * "Off" means the operator SET it off. It no longer means "unset": since the
	 * 2026-10-23 clarification both halves record by default, so an absent
	 * option resolves to ON. That case is asserted separately below rather than
	 * folded in here, because the two are different claims and only one of them
	 * is FR-029's.
	 */
	public function test_the_resolver_hands_back_the_null_handler_while_logging_is_off(): void {
		// NOT update_option( ..., false ) on an absent option: that is a silent
		// no-op. update_option() compares the new value against
		// `get_option( $option )`, which with no registered default is itself
		// false, finds them equal and writes nothing — so the option stays
		// absent and the true fallback keeps logging on. Registering the
		// setting first is what makes the write land, and it is also what the
		// real admin does before options.php saves anything.
		$this->register_activity_settings();
		update_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, false );

		$this->assertSame(
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			HandlerResolver::class_string()
		);

		update_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, true );

		$this->assertSame( ActivityObservabilityHandler::class, HandlerResolver::class_string() );

		delete_option( ActivityLogConstants::OPTION_REQUEST_LOGGING );
	}

	/**
	 * An install where nobody has touched the setting RECORDS.
	 *
	 * This is the whole of the 2026-10-23 default change as the running code
	 * sees it: not a value in the settings form, but which handler the adapter
	 * is given on a site that never opened that form. Asserted against the
	 * absent option specifically — `register_setting()`'s declared default and
	 * the constant passed to `get_option()` are two separate mechanisms that
	 * can disagree, and only the second one runs here.
	 */
	public function test_an_untouched_install_records_by_default(): void {
		delete_option( ActivityLogConstants::OPTION_REQUEST_LOGGING );

		$this->assertTrue(
			ActivityLogConstants::DEFAULT_REQUEST_LOGGING,
			'Precondition: the shipped default is on.'
		);

		$this->assertSame(
			ActivityObservabilityHandler::class,
			HandlerResolver::class_string(),
			'With no stored preference the adapter must get the recording handler, not the Null one.'
		);
	}

	/**
	 * Unticking the box on an untouched install actually turns logging OFF.
	 *
	 * The regression this guards is subtle enough to have shipped unnoticed.
	 * An unchecked checkbox posts NO field, so `options.php` calls
	 * update_option() with null, which sanitizes to false. update_option()
	 * compares that against `get_option( $option )` with no explicit default —
	 * i.e. against `register_setting()`'s declared default. While that default
	 * said false and every reader defaulted to true, the comparison found
	 * false === false, stored nothing, and the readers' true fallback kept
	 * recording: the operator unticks the box, the page says Settings saved,
	 * and the log keeps filling.
	 *
	 * Simulates the save rather than asserting the declaration, because the
	 * declaration being wrong is precisely what looks fine on inspection.
	 */
	public function test_unticking_the_box_on_an_untouched_install_turns_logging_off(): void {
		$this->register_activity_settings();

		delete_option( ActivityLogConstants::OPTION_REQUEST_LOGGING );

		$this->assertSame(
			ActivityObservabilityHandler::class,
			HandlerResolver::class_string(),
			'Precondition: an untouched install records.'
		);

		// What options.php does for a checkbox that was not submitted.
		$submitted = null;
		update_option(
			ActivityLogConstants::OPTION_REQUEST_LOGGING,
			rest_sanitize_boolean( $submitted )
		);

		$this->assertSame(
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			HandlerResolver::class_string(),
			'Unticking the box must stop recording, not silently leave it on.'
		);

		delete_option( ActivityLogConstants::OPTION_REQUEST_LOGGING );
	}

	/**
	 * Declare the activity settings the way `admin_init` does.
	 *
	 * `register_setting()`'s default participates in update_option()'s
	 * change detection, so a test that writes these options without it is
	 * testing a state the admin never reaches.
	 */
	private function register_activity_settings(): void {
		register_setting(
			'acrossai_mcp_settings',
			ActivityLogConstants::OPTION_REQUEST_LOGGING,
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => ActivityLogConstants::DEFAULT_REQUEST_LOGGING,
			)
		);
	}

	// --- FR-044: the preview filter ---

	public function test_the_input_preview_filter_can_suppress_recording_arguments(): void {
		$tags = $this->tags( array( 'params' => array( 'name' => 'toolset/content' ) ) );

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $tags, 1.0 );
		$this->assertNotNull( $this->newest()->input_preview, 'Previews are recorded by default.' );

		$suppress = static fn() => null;
		add_filter( ActivityLogConstants::FILTER_INPUT_PREVIEW, $suppress );
		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $tags, 1.0 );
		remove_filter( ActivityLogConstants::FILTER_INPUT_PREVIEW, $suppress );

		$row = $this->newest();

		$this->assertNull( $row->input_preview, 'An install handling regulated data can suppress them.' );
		$this->assertNotNull( $row->input_bytes, 'The SIZE is still measured — it carries no content.' );
	}

	// --- T084: what redaction does and does not cover ---

	/**
	 * A sensitive KEY is redacted.
	 *
	 * The adapter's shared helper recognises 24 key patterns; reusing it rather
	 * than growing a second list is what keeps this log and Feature 097's change
	 * log from drifting apart on what counts as a secret.
	 */
	public function test_a_sensitive_key_is_redacted_from_the_preview(): void {
		$tags = $this->tags(
			array(
				'params' => array(
					'name'      => 'toolset/content',
					'api_key'   => 'sk-live-must-not-appear',
					'client_secret' => 'also-must-not-appear',
				),
			)
		);

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $tags, 1.0 );

		$preview = (string) $this->newest()->input_preview;

		$this->assertStringNotContainsString( 'sk-live-must-not-appear', $preview );
		$this->assertStringNotContainsString( 'also-must-not-appear', $preview );
		// wp_json_encode escapes forward slashes, so match the encoded form.
		$this->assertStringContainsString(
			'toolset\\/content',
			$preview,
			'Non-sensitive values still help diagnosis.'
		);
		$this->assertStringContainsString( '[REDACTED]', $preview );
	}

	/**
	 * A secret inside a FREE-TEXT VALUE is NOT redacted — and the spec says so.
	 *
	 * This test documents a known limit rather than a defect. Redaction matches
	 * key names, so a credential pasted into a `content`, `search` or `message`
	 * field matches nothing and is stored as sent, subject to truncation.
	 * Asserting it keeps the limit honest: if someone later believes previews
	 * are safe for regulated data, this test says otherwise in writing.
	 *
	 * FR-044's filter is the escape hatch, exercised by the test above it.
	 */
	public function test_a_secret_in_a_free_text_value_is_NOT_redacted_which_is_documented(): void {
		$tags = $this->tags(
			array(
				'params' => array( 'content' => 'My password is hunter2, please remember it' ),
			)
		);

		( new ActivityObservabilityHandler() )->record_event( 'mcp.request', $tags, 1.0 );

		$preview = (string) $this->newest()->input_preview;

		$this->assertStringContainsString(
			'hunter2',
			$preview,
			'Key-name redaction cannot cover free-text values. This is the documented limit (FR-044, SC-C3), not a bug — if this assertion ever flips, the spec and the settings copy must change with it.'
		);
	}

	/** The server slug resolves to a numeric id, and 0 when it does not. */
	public function test_an_unresolvable_server_slug_records_zero_and_keeps_the_slug(): void {
		( new ActivityObservabilityHandler() )->record_event(
			'mcp.request',
			$this->tags( array( 'server_id' => 'a-server-that-was-deleted' ) ),
			1.0
		);

		$row = $this->newest();

		$this->assertSame( 0, $row->server_id );
		$this->assertSame(
			'a-server-that-was-deleted',
			$row->server_slug,
			'History must stay readable after a server is deleted (SC-014).'
		);
	}
}
