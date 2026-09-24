<?php
/**
 * F091 — value repair for columns the reconciler just created.
 *
 * Restoring a column is not the same as repairing the site: a restored
 * protocol-flag column arrives ENABLED, which is right for an `mcp-adapter`
 * server and wrong for an `acrossai` one. These tests protect the three ways
 * that repair could go wrong rather than merely not work:
 *
 *   1. Reading the server type BEFORE repairing it, which re-enables the exact
 *      three tools the feature exists to remove (test_server_type_is_corrected_
 *      before_flags_are_read — the highest-severity case).
 *   2. Writing a column that already existed, which overwrites an operator's
 *      deliberate choice (test_pre_existing_columns_are_never_touched).
 *   3. Trusting a type that declares nothing, whose accessor falls back to the
 *      legacy list — the three protocol tools again
 *      (test_unknown_or_empty_type_leaves_flags_alone).
 *
 * Harness notes:
 *   - `set_up()` / `tear_down()` MUST be public — this WP test library fatals
 *     on protected ones (B56).
 *   - DDL commits and escapes the per-test rollback (B53), so the servers table
 *     is reinstalled unconditionally in `tear_down()`.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\CreatedColumnBackfill;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerGuideBackfill;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\SchemaReconciler;
use WP_UnitTestCase;

class CreatedColumnBackfillTest extends WP_UnitTestCase {

	private const FLAGS = array(
		'tool_discover_abilities',
		'tool_get_ability_info',
		'tool_execute_ability',
	);

	/** @var int[] */
	private $created = array();

	/** @var bool */
	private $altered = false;

	public function set_up(): void {
		parent::set_up();

		MCPServerTable::instance()->maybe_upgrade();

		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		delete_transient( SchemaReconciler::LOCK_TRANSIENT );
		SchemaReconciler::reset_request_cache();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		foreach ( $this->created as $id ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		}

		$this->created = array();

		// A test that dropped columns leaves the table mutilated for every later
		// test in the suite, because DDL escapes the rollback. Reinstall.
		if ( $this->altered ) {
			$version_key = 'acrossai_mcp_servers_db_version';

			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			delete_option( $version_key );
			delete_transient( $version_key . '_upgrade_lock' );
			MCPServerTable::instance()->maybe_upgrade();

			$this->altered = false;
		}

		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		delete_transient( SchemaReconciler::LOCK_TRANSIENT );
		delete_option( ServerGuideBackfill::DONE_OPTION );
		SchemaReconciler::reset_request_cache();
		remove_all_filters( ServerTypes::FILTER );

		parent::tear_down();
	}

	/**
	 * The managed server must come back with its Toolsets, not with the three
	 * protocol tools its restored columns default to.
	 */
	public function test_flags_follow_declared_type_when_columns_were_just_created(): void {
		$acrossai    = $this->server( 'acrossai' );
		$mcp_adapter = $this->server( ServerTypes::LEGACY );

		$this->drop_columns( self::FLAGS );

		$created = SchemaReconciler::reconcile( MCPServerTable::instance() );

		foreach ( self::FLAGS as $flag ) {
			$this->assertContains( $flag, $created, 'the reconciler must have restored ' . $flag );
		}

		CreatedColumnBackfill::apply( $created );

		$this->assertSame(
			array( 0, 0, 0 ),
			$this->flags_of( $acrossai ),
			'an acrossai server must not advertise the three protocol tools'
		);

		$this->assertSame(
			array( 1, 1, 1 ),
			$this->flags_of( $mcp_adapter ),
			'an mcp-adapter server must keep the three protocol tools its type declares'
		);
	}

	/**
	 * THE ORDERING REGRESSION.
	 *
	 * On a site stamped at the current version, `server_type` is missing too.
	 * Restore it and every row reads `mcp-adapter`, so a backfill that read the
	 * type before repairing it would resolve the legacy tool list and switch all
	 * three flags ON — reproducing the bug and mistyping the server for good.
	 */
	public function test_server_type_is_corrected_before_flags_are_read(): void {
		$slug = $this->seeded_acrossai_slug();

		if ( '' === $slug ) {
			$this->markTestSkipped( 'no seeded acrossai server is declared' );
		}

		$id = $this->server( 'acrossai', $slug );

		$this->drop_columns( array_merge( self::FLAGS, array( 'server_type' ) ) );

		$created = SchemaReconciler::reconcile( MCPServerTable::instance() );

		$this->assertContains( 'server_type', $created, 'setup: server_type must have been restored' );

		CreatedColumnBackfill::apply( $created );

		$this->assertSame(
			'acrossai',
			$this->type_of( $id ),
			'the seeded server must be retyped before anything reads its type'
		);

		$this->assertSame(
			array( 0, 0, 0 ),
			$this->flags_of( $id ),
			'flags must follow the CORRECTED type, not the column default'
		);
	}

	/**
	 * The doctrine assertion. A column that already existed carries whatever the
	 * operator chose, and is not this repair's business.
	 */
	public function test_pre_existing_columns_are_never_touched(): void {
		$id = $this->server( 'acrossai' );

		MCPServerQuery::instance()->update_item(
			$id,
			array(
				'tool_discover_abilities' => 1,
				'tool_get_ability_info'   => 1,
				'tool_execute_ability'    => 1,
			)
		);

		// Nothing was dropped, so the reconciler creates nothing.
		$created = SchemaReconciler::reconcile( MCPServerTable::instance() );

		$this->assertSame( array(), $created, 'setup: an intact table must need no repair' );

		CreatedColumnBackfill::apply( $created );

		$this->assertSame(
			array( 1, 1, 1 ),
			$this->flags_of( $id ),
			'a deliberate operator choice must survive the repair untouched'
		);
	}

	/**
	 * A type that declares no tools must not have the legacy fallback applied to
	 * it. `declared_tools()` would answer with the three protocol tools.
	 */
	public function test_unknown_or_empty_type_leaves_flags_alone(): void {
		add_filter(
			ServerTypes::FILTER,
			static function ( $types ) {
				$types['empty-type'] = array(
					'label'       => 'Empty',
					'description' => 'Declares no tools.',
					'tools'       => array(),
				);

				return $types;
			}
		);

		$id = $this->server( 'empty-type' );

		$this->drop_columns( self::FLAGS );

		$created = SchemaReconciler::reconcile( MCPServerTable::instance() );

		CreatedColumnBackfill::apply( $created );

		$this->assertSame(
			array( 1, 1, 1 ),
			$this->flags_of( $id ),
			'a type that declares nothing must be left on the column default, not silently zeroed or enabled by a fallback'
		);
	}

	/**
	 * The guide backfill already recorded success without doing its work on
	 * exactly the sites this feature targets, because its query errored against
	 * the missing column. Restoring the column is not enough; its flag must go.
	 */
	public function test_server_guide_backfill_flag_is_cleared_when_type_column_is_created(): void {
		update_option( ServerGuideBackfill::DONE_OPTION, 1, false );

		$this->drop_columns( array( 'server_type' ) );

		$created = SchemaReconciler::reconcile( MCPServerTable::instance() );

		CreatedColumnBackfill::apply( $created );

		$this->assertFalse(
			(bool) get_option( ServerGuideBackfill::DONE_OPTION ),
			'the guide backfill must be allowed to run now that it can'
		);
	}

	/**
	 * An empty created-column report is a no-op, not an excuse to walk the table.
	 */
	public function test_empty_report_writes_nothing(): void {
		$id = $this->server( 'acrossai' );

		MCPServerQuery::instance()->update_item( $id, array( 'tool_discover_abilities' => 1 ) );

		CreatedColumnBackfill::apply( array() );

		$this->assertSame( 1, $this->flags_of( $id )[0], 'an empty report must change nothing' );
	}

	// -- helpers -----------------------------------------------------------

	private function server( string $type, string $slug = '' ): int {
		$slug = ( '' !== $slug ) ? $slug : 'ccb-test-' . wp_generate_password( 6, false, false );

		$id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'CreatedColumnBackfill test',
				'server_slug'            => $slug,
				'description'            => 'Seeded by CreatedColumnBackfillTest',
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
				'server_type'            => $type,
			)
		);

		$this->created[] = $id;

		return $id;
	}

	/**
	 * @param string[] $columns
	 */
	private function drop_columns( array $columns ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		$this->altered = true;

		foreach ( $columns as $column ) {
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" );
		}

		SchemaReconciler::reset_request_cache();
		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
	}

	/**
	 * @return int[]
	 */
	private function flags_of( int $id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );

		return array(
			(int) ( $row['tool_discover_abilities'] ?? -1 ),
			(int) ( $row['tool_get_ability_info'] ?? -1 ),
			(int) ( $row['tool_execute_ability'] ?? -1 ),
		);
	}

	private function type_of( int $id ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT server_type FROM `{$table}` WHERE id = %d", $id ) );
	}

	private function seeded_acrossai_slug(): string {
		foreach ( ServerTypes::seeded_servers() as $slug => $seeded ) {
			if ( 'acrossai' === ( $seeded['type'] ?? '' ) ) {
				return (string) $slug;
			}
		}

		return '';
	}
}
