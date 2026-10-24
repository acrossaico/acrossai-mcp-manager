<?php
/**
 * Privacy exporter and eraser contract (Feature 096, T083).
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Observability\PersonalData;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Observability\PersonalData
 */
class PersonalDataTest extends \WP_UnitTestCase {

	/** @var int */
	private static $subject_id = 0;

	/** @var string */
	private static $subject_email = '';

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$subject_id    = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'subject@example.test',
			)
		);
		self::$subject_email = 'subject@example.test';
	}

	public function set_up() {
		parent::set_up();
		Table::instance()->maybe_upgrade();
	}

	private function seed_for_subject(): int {
		return Recorder::record_request(
			array(
				'method'          => 'tools/call',
				'component_name'  => 'content/update-post',
				'status'          => 'success',
				'server_slug'     => 'acrossai-mcp-server',
				'connection_type' => 'ai-connector',
				'user_id'         => self::$subject_id,
				'ip_address'      => '203.0.113.42',
				'user_agent'      => 'Claude/1.0',
				'client_name'     => 'Claude Desktop',
				'client_version'  => '1.0',
			)
		);
	}

	// --- Registration ---

	public function test_both_callbacks_register(): void {
		$exporters = PersonalData::register_exporter( array() );
		$erasers   = PersonalData::register_eraser( array() );

		$this->assertArrayHasKey( 'acrossai-mcp-activity-log', $exporters );
		$this->assertIsCallable( $exporters['acrossai-mcp-activity-log']['callback'] );
		$this->assertArrayHasKey( 'acrossai-mcp-activity-log', $erasers );
		$this->assertIsCallable( $erasers['acrossai-mcp-activity-log']['callback'] );
	}

	/** A filter can be handed anything; a non-array must pass straight through. */
	public function test_a_non_array_filter_value_passes_through_untouched(): void {
		$this->assertSame( 'nonsense', PersonalData::register_exporter( 'nonsense' ) );
		$this->assertSame( 'nonsense', PersonalData::register_eraser( 'nonsense' ) );
	}

	// --- Export ---

	/**
	 * SC-C12: the export includes source addresses.
	 *
	 * An export that silently omitted them would give the subject an incomplete
	 * picture of what is held about them — and the full address was kept in the
	 * first place only on condition that this path existed.
	 */
	public function test_the_export_includes_the_source_address(): void {
		$this->seed_for_subject();

		$result = PersonalData::export( self::$subject_email, 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );

		$fields = wp_list_pluck( $result['data'][0]['data'], 'value', 'name' );

		$this->assertContains( '203.0.113.42', $fields, 'The address must be disclosed to its subject.' );
		$this->assertContains( 'content/update-post', $fields );
		$this->assertContains( 'Claude/1.0', $fields );
	}

	public function test_an_unknown_email_exports_nothing_and_finishes(): void {
		$result = PersonalData::export( 'nobody@example.test', 1 );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/** One subject's export must not disclose another's events. */
	public function test_the_export_is_scoped_to_its_subject(): void {
		$this->seed_for_subject();

		$other = self::factory()->user->create( array( 'user_email' => 'other@example.test' ) );
		Recorder::record_request( array( 'method' => 'tools/list', 'user_id' => $other ) );

		$result = PersonalData::export( self::$subject_email, 1 );

		$this->assertCount( 1, $result['data'], 'Only the subject\'s own events.' );
	}

	// --- Erase: the decision that matters ---

	/**
	 * D60 / SC-C2 — erasure DE-IDENTIFIES and must not delete.
	 *
	 * WordPress's eraser runs on an unprivileged subject request. An eraser
	 * that deleted rows would hand anyone able to file one a way to destroy
	 * audit evidence — no `manage_options` required. Clearing the identifiers
	 * satisfies the request completely, because the subject is no longer
	 * identifiable, while the security record survives.
	 *
	 * This is the single most important assertion in the file.
	 */
	public function test_erasure_de_identifies_and_removes_no_event(): void {
		$id     = $this->seed_for_subject();
		$before = Query::instance()->query( array( 'count' => true ) );

		$result = PersonalData::erase( self::$subject_email, 1 );

		$this->assertTrue( $result['items_removed'], 'Personal data was acted on.' );
		$this->assertTrue( $result['items_retained'], 'And the events themselves were kept.' );

		$after = Query::instance()->query( array( 'count' => true ) );

		$this->assertSame(
			(int) $before,
			(int) $after,
			'Erasure must not delete events — that would make a subject request an evidence-destruction path.'
		);

		$row = Query::instance()->get_item( $id );

		$this->assertInstanceOf( Row::class, $row, 'The event still exists.' );
		$this->assertSame( 0, $row->user_id, 'The acting identity is cleared.' );
		$this->assertSame( '', $row->ip_address, 'The address is cleared.' );
		$this->assertSame( '', $row->user_agent );
		$this->assertSame( '', $row->client_name );
		$this->assertSame( '', $row->client_version );
	}

	/** What the event WAS must survive, or the audit record is worthless. */
	public function test_erasure_preserves_what_happened(): void {
		$id = $this->seed_for_subject();

		PersonalData::erase( self::$subject_email, 1 );

		$row = Query::instance()->get_item( $id );

		$this->assertSame( 'content/update-post', $row->component_name );
		$this->assertSame( 'tools/call', $row->method );
		$this->assertSame( 'success', $row->status );
		$this->assertSame( 'acrossai-mcp-server', $row->server_slug );
		$this->assertNotSame( '', $row->created_at );
	}

	/** The operator must be able to explain the outcome to the person who asked. */
	public function test_the_eraser_explains_that_events_are_retained(): void {
		$this->seed_for_subject();

		$result = PersonalData::erase( self::$subject_email, 1 );

		$this->assertNotEmpty( $result['messages'] );
		$this->assertStringContainsString( 'de-identified', strtolower( $result['messages'][0] ) );
	}

	/** After erasure the subject's export returns nothing attributable. */
	public function test_an_erased_subject_exports_nothing(): void {
		$this->seed_for_subject();

		PersonalData::erase( self::$subject_email, 1 );

		$result = PersonalData::export( self::$subject_email, 1 );

		$this->assertSame( array(), $result['data'], 'Nothing remains attributable to this person.' );
	}

	/** One subject's erasure must not touch another's data. */
	public function test_erasure_is_scoped_to_its_subject(): void {
		$this->seed_for_subject();

		$other    = self::factory()->user->create( array( 'user_email' => 'other@example.test' ) );
		$other_id = Recorder::record_request(
			array(
				'method'     => 'tools/list',
				'user_id'    => $other,
				'ip_address' => '198.51.100.7',
			)
		);

		PersonalData::erase( self::$subject_email, 1 );

		$row = Query::instance()->get_item( $other_id );

		$this->assertSame( $other, $row->user_id, 'Another subject is untouched.' );
		$this->assertSame( '198.51.100.7', $row->ip_address );
	}

	public function test_erasing_an_unknown_email_is_a_no_op(): void {
		$result = PersonalData::erase( 'nobody@example.test', 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}
}
