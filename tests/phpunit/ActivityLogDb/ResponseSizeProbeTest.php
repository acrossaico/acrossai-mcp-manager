<?php
/**
 * Response-size probe contract (Feature 096, T074 + T075).
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Schema;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Observability\ActivityObservabilityHandler;
use AcrossAI_MCP_Manager\Includes\Observability\ResponseSizeProbe;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Observability\ResponseSizeProbe
 */
class ResponseSizeProbeTest extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Table::instance()->maybe_upgrade();
		ResponseSizeProbe::reset();
	}

	public function tear_down() {
		ResponseSizeProbe::reset();
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

	/**
	 * Every callback must hand its input straight back.
	 *
	 * This is the whole safety property. These filters sit on the path that
	 * produces every MCP response, so a probe that altered a payload — even by
	 * re-encoding it — would corrupt what the client receives. Asserting
	 * identity rather than equality catches a callback that rebuilt an
	 * equivalent value.
	 *
	 * @dataProvider pass_through_cases
	 */
	public function test_every_callback_returns_its_input_unchanged( string $method, int $args ): void {
		$probe   = ResponseSizeProbe::instance();
		$payload = array(
			'content' => array( array( 'type' => 'text', 'text' => 'hello' ) ),
			'nested'  => (object) array( 'a' => 1 ),
		);

		$returned = 3 === $args
			? $probe->{$method}( $payload, array( 'ignored' => true ), 'ignored' )
			: $probe->{$method}( $payload );

		$this->assertSame(
			$payload,
			$returned,
			sprintf( '%s must return its input unchanged — it measures, it does not transform.', $method )
		);
	}

	/**
	 * Data provider. Annotation, not an attribute: PHPUnit is pinned to ^9.6
	 * because WP's abstract testcase calls APIs removed in 10.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function pass_through_cases(): array {
		return array(
			'tool_call_result'    => array( 'measure_tool_call', 3 ),
			'tools_list'          => array( 'measure_tools_list', 1 ),
			'initialize_response' => array( 'measure_initialize', 1 ),
			'prompt_get_result'   => array( 'measure_prompt_get', 3 ),
			'prompts_list'        => array( 'measure_prompts_list', 1 ),
			'resource_read'       => array( 'measure_resource_read', 3 ),
			'resources_list'      => array( 'measure_resources_list', 1 ),
		);
	}

	/** A null payload is "not measured", not zero. */
	public function test_a_null_payload_leaves_the_measurement_unset(): void {
		$probe = ResponseSizeProbe::instance();

		$this->assertNull( $probe->measure_tools_list( null ) );
		$this->assertNull( ResponseSizeProbe::take(), 'Nothing measured means null, not 0.' );
	}

	public function test_a_measured_payload_reports_its_json_byte_length(): void {
		$payload  = array( 'tools' => array( 'a', 'b', 'c' ) );
		$expected = strlen( (string) wp_json_encode( $payload ) );

		ResponseSizeProbe::instance()->measure_tools_list( $payload );

		$this->assertSame( $expected, ResponseSizeProbe::take() );
	}

	/**
	 * Sizes accumulate across one request: a JSON-RPC batch can carry several
	 * results, and the client's context pays for all of them.
	 */
	public function test_multiple_results_in_one_request_accumulate(): void {
		$probe = ResponseSizeProbe::instance();

		$probe->measure_tool_call( array( 'a' => 1 ), null, null );
		$probe->measure_tool_call( array( 'b' => 2 ), null, null );

		$one = strlen( (string) wp_json_encode( array( 'a' => 1 ) ) );
		$two = strlen( (string) wp_json_encode( array( 'b' => 2 ) ) );

		$this->assertSame( $one + $two, ResponseSizeProbe::take() );
	}

	/**
	 * `take()` clears. Leaving the value behind would attribute one response's
	 * size to the next request on a long-lived process — the same class of leak
	 * A17's shutdown stage exists to prevent.
	 */
	public function test_take_clears_so_a_size_cannot_leak_into_the_next_request(): void {
		ResponseSizeProbe::instance()->measure_tools_list( array( 'x' ) );

		$this->assertNotNull( ResponseSizeProbe::take() );
		$this->assertNull( ResponseSizeProbe::take(), 'A second read must not repeat the first.' );
	}

	/** The measured size reaches the row. */
	public function test_the_handler_records_the_measured_size(): void {
		ResponseSizeProbe::instance()->measure_tool_call( array( 'content' => 'hello' ), null, null );
		$expected = strlen( (string) wp_json_encode( array( 'content' => 'hello' ) ) );

		( new ActivityObservabilityHandler() )->record_event(
			'mcp.request',
			array(
				'method'    => 'tools/call',
				'server_id' => 'acrossai-mcp-server',
				'status'    => 'success',
			),
			5.0
		);

		$this->assertSame( $expected, $this->newest()->output_bytes );
	}

	/**
	 * The case that cannot be rolled up from children.
	 *
	 * `tools/list` runs no ability, so it has no child events — summing
	 * `output_bytes` over `parent_id` would miss it entirely. And the tool
	 * catalogue injected at connect, on every new conversation, is usually the
	 * largest single context cost a server imposes. This is why
	 * `mcp_adapter_tools_list` is hooked rather than inferred.
	 */
	public function test_a_tools_list_row_records_its_size_despite_having_no_children(): void {
		ResponseSizeProbe::instance()->measure_tools_list(
			array_fill( 0, 20, array( 'name' => 'toolset/content', 'description' => 'A long description.' ) )
		);

		( new ActivityObservabilityHandler() )->record_event(
			'mcp.request',
			array(
				'method'    => 'tools/list',
				'server_id' => 'acrossai-mcp-server',
				'status'    => 'success',
			),
			6.0
		);

		$row = $this->newest();

		$this->assertSame( 'tools/list', $row->method );
		$this->assertGreaterThan( 0, $row->output_bytes );
		$this->assertSame( 0, Query::instance()->count_children( (int) $row->id ) );
	}

	/** A measuring failure must not break the response it observes. */
	public function test_a_failure_while_measuring_returns_the_payload_anyway(): void {
		// A resource that cannot be JSON-encoded. wp_json_encode returns false
		// rather than throwing, so the payload must still come back untouched.
		$payload = array( 'handle' => fopen( 'php://memory', 'r' ) );

		$returned = ResponseSizeProbe::instance()->measure_tool_call( $payload, null, null );

		$this->assertSame( $payload, $returned );

		if ( is_resource( $payload['handle'] ) ) {
			fclose( $payload['handle'] );
		}
	}

	// --- T075: no token column may exist ---

	/**
	 * FR-022 — the schema must hold no token column.
	 *
	 * Bytes are a fact; a token count is a guess. A guess living in a column
	 * named `tokens_output` gets aggregated into a cost report six months later
	 * by someone who never read the documentation saying it was approximate.
	 * The WordPress AI plugin legitimately has these columns because it calls
	 * the model and the provider reports usage; we are the tool server and
	 * never see a usage block, so ours would be permanently null or
	 * permanently wrong.
	 */
	public function test_the_schema_declares_no_token_column(): void {
		$forbidden = array( 'tokens_input', 'tokens_output', 'tokens_total', 'token_count' );
		$declared  = array();

		foreach ( ( new Schema() )->columns as $column ) {
			$declared[] = (string) $column->name;
		}

		foreach ( $forbidden as $name ) {
			$this->assertNotContains(
				$name,
				$declared,
				sprintf( '%s must not exist: this table cannot measure LLM token usage (FR-022).', $name )
			);
		}

		$this->assertContains( 'output_bytes', $declared, 'Bytes are what we can actually measure.' );
		$this->assertContains( 'input_bytes', $declared );
	}

	/** And the live table matches. */
	public function test_the_live_table_has_no_token_column(): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );

		foreach ( (array) $rows as $row ) {
			$this->assertStringNotContainsString(
				'token',
				strtolower( (string) $row->Field ),
				'No live column may mention tokens.'
			);
		}
	}
}
