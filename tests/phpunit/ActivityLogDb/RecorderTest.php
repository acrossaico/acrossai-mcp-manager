<?php
/**
 * Recorder contract for the activity log (Feature 096, T020).
 *
 * Needs a real database for the round-trip. Runs under tests/bootstrap-wp.php.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use ReflectionClass;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder
 * @covers \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query
 */
class RecorderTest extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Table::instance()->maybe_upgrade();
	}

	public function test_records_a_request_and_reads_it_back(): void {
		$id = Recorder::record_request(
			array(
				'server_slug'      => 'acrossai-mcp-server',
				'method'           => 'tools/call',
				'component_type'   => 'tool',
				'component_name'   => 'toolset/server-guide',
				'invoked_name'     => 'toolset-server-guide',
				'status'           => 'success',
				'duration_ms'      => 36,
				'connection_type'  => 'app-password',
				'transport'        => 'HTTP',
				'protocol_version' => '2025-06-18',
				'output_bytes'     => 2048,
			)
		);

		$this->assertGreaterThan( 0, $id );

		$row = Query::instance()->get_item( $id );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertSame( ActivityLogConstants::EVENT_REQUEST, $row->event_type );
		$this->assertSame( 0, $row->parent_id, 'A request event never has a parent.' );
		$this->assertSame( 'toolset/server-guide', $row->component_name );
		$this->assertSame( 'toolset-server-guide', $row->invoked_name );
		$this->assertSame( 'mcp', $row->event_source, 'event_source defaults to mcp.' );
		$this->assertSame( 36, $row->duration_ms );
		$this->assertSame( 2048, $row->output_bytes );
		$this->assertNotSame( '', $row->created_at, 'created_at must be stamped automatically.' );
	}

	/** An ability event beneath a request is the parent/child shape of the table. */
	public function test_records_an_ability_beneath_its_request(): void {
		$parent = Recorder::record_request( array( 'method' => 'tools/call' ) );

		$child = Recorder::record_ability(
			array(
				'parent_id'      => $parent,
				'component_name' => 'content/update-post',
				'invoked_via'    => 'mcp',
				'status'         => 'success',
			)
		);

		$this->assertGreaterThan( 0, $child );

		$children = Query::instance()->children( $parent );

		$this->assertCount( 1, $children );
		$this->assertSame( 'content/update-post', $children[0]->component_name );
		$this->assertSame( ActivityLogConstants::EVENT_ABILITY, $children[0]->event_type );
		$this->assertSame( 1, Query::instance()->count_children( $parent ) );
	}

	/** A non-MCP ability run has no parent, and that is correct, not missing data. */
	public function test_a_non_mcp_ability_has_no_parent(): void {
		$id  = Recorder::record_ability(
			array(
				'component_name' => 'content/update-post',
				'invoked_via'    => 'rest',
				'status'         => 'success',
			)
		);
		$row = Query::instance()->get_item( $id );

		$this->assertSame( 0, $row->parent_id );
		$this->assertSame( 'rest', $row->invoked_via );
	}

	/**
	 * B7: a forged key must not reach the insert. The column map is declarative
	 * so future columns are additive — which is exactly why it must be filtered
	 * rather than trusted.
	 */
	public function test_undeclared_keys_are_dropped_not_persisted(): void {
		$id = Recorder::record_request(
			array(
				'method'            => 'ping',
				'user_can_do_evil'  => 1,
				'wp_capability'      => 'manage_options',
			)
		);

		$this->assertGreaterThan( 0, $id, 'A forged key must not abort the legitimate write.' );

		$row = Query::instance()->get_item( $id );

		$this->assertSame( 'ping', $row->method );
		$this->assertFalse( property_exists( $row, 'user_can_do_evil' ) );
	}

	/**
	 * A24: deletion of events is itself recorded, and the record is written
	 * AFTER the delete so it survives as the first entry of what remains.
	 */
	public function test_a_removal_is_itself_recorded(): void {
		Recorder::record_request( array( 'method' => 'ping' ) );

		$id  = Recorder::record_removal( 'purge', 17, 5 );
		$row = Query::instance()->get_item( $id );

		$this->assertSame( ActivityLogConstants::EVENT_REMOVAL, $row->event_type );
		$this->assertSame( 'purge', $row->component_name );
		$this->assertSame( '17', $row->input_preview, 'The count removed must be recoverable.' );
		$this->assertSame( 5, $row->user_id );
		$this->assertSame( 'admin', $row->invoked_via, 'A user-triggered purge is not cron.' );
	}

	/** Retention prunes per event kind, never on one shared cutoff. */
	public function test_retention_prunes_only_the_named_event_kind(): void {
		global $wpdb;

		$table   = $wpdb->prefix . ActivityLogConstants::TABLE;
		$request = Recorder::record_request( array( 'method' => 'ping' ) );
		$ability = Recorder::record_ability( array( 'component_name' => 'content/read' ) );

		// Age both rows well past any cutoff.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE `{$table}` SET created_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 90 DAY )" );

		$deleted = Query::instance()->prune_by_event_type( ActivityLogConstants::EVENT_REQUEST, 30 );

		$this->assertGreaterThanOrEqual( 1, $deleted );
		$this->assertNotInstanceOf(
			Row::class,
			Query::instance()->get_item( $request ),
			'The request event should be gone.'
		);
		$this->assertInstanceOf(
			Row::class,
			Query::instance()->get_item( $ability ),
			'Pruning requests must not touch ability events — that is the whole point of a per-kind cutoff.'
		);
	}

	/** A cutoff of 0 means "keep indefinitely" and must delete nothing. */
	public function test_a_zero_cutoff_deletes_nothing(): void {
		Recorder::record_request( array( 'method' => 'ping' ) );

		$this->assertSame( 0, Query::instance()->prune_by_event_type( ActivityLogConstants::EVENT_REQUEST, 0 ) );
	}

	/** The ceiling trims oldest-first and keeps the newest. */
	public function test_the_ceiling_sheds_the_oldest_and_keeps_the_newest(): void {
		$ids = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = Recorder::record_request( array( 'method' => 'ping', 'request_id' => (string) $i ) );
		}

		$deleted = Query::instance()->enforce_ceiling( 3 );

		$this->assertSame( 3, $deleted );
		$this->assertNotInstanceOf( Row::class, Query::instance()->get_item( $ids[0] ), 'Oldest must go first.' );
		$this->assertInstanceOf( Row::class, Query::instance()->get_item( $ids[5] ), 'Newest must survive.' );
	}

	/** A ceiling of 0 disables trimming entirely. */
	public function test_a_zero_ceiling_disables_trimming(): void {
		Recorder::record_request( array( 'method' => 'ping' ) );

		$this->assertSame( 0, Query::instance()->enforce_ceiling( 0 ) );
	}

	/**
	 * FR-013: a recording failure must never propagate. Forced here by dropping
	 * the table from under the recorder — the harshest realistic failure.
	 */
	public function test_a_storage_failure_is_swallowed_and_returns_zero(): void {
		global $wpdb;

		// Dropping the table does NOT work as a forced failure: the phantom
		// version guard self-heals and the write succeeds. That is correct
		// behaviour and worth knowing — so break the SQL itself instead, which
		// is also the realistic failure (a malformed query, a lock, a full disk).
		$break = static function ( $query ) {
			return false !== stripos( (string) $query, 'acrossai_mcp_activity_log' )
				? 'SELECT this_is_not_valid_sql FROM nowhere_at_all'
				: $query;
		};

		add_filter( 'query', $break );
		$suppressed = $wpdb->suppress_errors( true );

		$id = Recorder::record_request( array( 'method' => 'ping' ) );

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );

		$this->assertSame( 0, $id, 'A failed write returns 0; it must not throw.' );
	}

	/** The self-healing observed above, asserted so it is not lost. */
	public function test_a_dropped_table_is_reinstalled_rather_than_losing_the_write(): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		$id = Recorder::record_request( array( 'method' => 'ping' ) );

		$this->assertGreaterThan( 0, $id, 'The phantom-version guard should reinstall and the write should land.' );
		$this->assertTrue( Table::instance()->exists() );
	}

	/** A15: static-only, no constructor, no instance(), never Loader-wired. */
	public function test_satisfies_the_a15_recorder_contract(): void {
		$ref = new ReflectionClass( Recorder::class );

		$this->assertTrue( $ref->isFinal() );
		$this->assertNull( $ref->getConstructor(), 'A15 recorders declare no constructor.' );
		$this->assertFalse( $ref->hasMethod( 'instance' ), 'A15 recorders are not singletons.' );

		foreach ( $ref->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			$this->assertTrue(
				$method->isStatic(),
				sprintf( 'A15 requires every public method to be static; %s is not.', $method->getName() )
			);
		}
	}
}
