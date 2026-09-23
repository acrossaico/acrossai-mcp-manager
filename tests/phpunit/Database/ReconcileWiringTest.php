<?php
/**
 * F091 — the repair is actually wired, end to end.
 *
 * Every other F091 test calls `SchemaReconciler` directly. That proves the
 * mechanism and proves nothing about whether anything invokes it — and a repair
 * nobody calls is the same as no repair at all. The production entry point is
 * `Main::reconcile_database_schemas()` on `admin_init`, so this drives THAT and
 * asserts a drifted table comes back healed with correct values.
 *
 * It reproduces the exact production state: the columns a site stamped in the
 * 1.1.1–1.1.2 range is missing, with the version option claiming the current
 * version so no migration will ever run again.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\SchemaReconciler;
use AcrossAI_MCP_Manager\Includes\Main;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class ReconcileWiringTest extends WP_UnitTestCase {

	private const DRIFTED = array(
		'tool_discover_abilities',
		'tool_get_ability_info',
		'tool_execute_ability',
		'override_abilities_permission',
	);

	/** @var int[] */
	private $created = array();

	public function set_up(): void {
		parent::set_up();

		MCPServerTable::instance()->maybe_upgrade();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		delete_transient( SchemaReconciler::LOCK_TRANSIENT );
		SchemaReconciler::reset_request_cache();
	}

	public function tear_down(): void {
		global $wpdb;

		$table       = $wpdb->prefix . 'acrossai_mcp_servers';
		$version_key = 'acrossai_mcp_servers_db_version';

		foreach ( $this->created as $id ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		}

		$this->created = array();

		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		delete_option( $version_key );
		delete_transient( $version_key . '_upgrade_lock' );
		MCPServerTable::instance()->maybe_upgrade();

		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		delete_transient( SchemaReconciler::LOCK_TRANSIENT );
		SchemaReconciler::reset_request_cache();

		parent::tear_down();
	}

	/**
	 * The production entry point repairs a table that BerlinDB believes is
	 * already current — and gives the restored columns the right values.
	 */
	public function test_admin_init_entry_point_heals_the_production_drift(): void {
		global $wpdb;

		$table       = $wpdb->prefix . 'acrossai_mcp_servers';
		$version_key = 'acrossai_mcp_servers_db_version';

		$server_id = $this->server( 'acrossai' );

		foreach ( self::DRIFTED as $column ) {
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" );
		}

		// The phantom stamp: claim the declared version while the columns are
		// gone, so no `$upgrades` callback can ever run again.
		$declared = (string) ( new ReflectionClass( MCPServerTable::class ) )->getDefaultProperties()['version'];
		update_option( $version_key, $declared );
		delete_transient( $version_key . '_upgrade_lock' );

		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		SchemaReconciler::reset_request_cache();

		foreach ( self::DRIFTED as $column ) {
			$this->assertFalse(
				MCPServerTable::instance()->column_exists( $column ),
				"setup: {$column} must be missing"
			);
		}

		// THE PRODUCTION PATH — what admin_init@3 calls.
		Main::instance()->reconcile_database_schemas();

		foreach ( self::DRIFTED as $column ) {
			$this->assertTrue(
				MCPServerTable::instance()->column_exists( $column ),
				"{$column} must be restored by the admin_init entry point"
			);
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $server_id ), ARRAY_A );

		$this->assertSame(
			array( '0', '0', '0' ),
			array(
				(string) $row['tool_discover_abilities'],
				(string) $row['tool_get_ability_info'],
				(string) $row['tool_execute_ability'],
			),
			'the managed server must not come back advertising the three protocol tools'
		);
	}

	/**
	 * A second run is free: the fingerprint short-circuits the whole pass.
	 */
	public function test_second_pass_short_circuits_on_the_fingerprint(): void {
		Main::instance()->reconcile_database_schemas();

		$stamped = (string) get_option( SchemaReconciler::FINGERPRINT_OPTION, '' );

		$this->assertNotSame( '', $stamped, 'a clean pass must record its fingerprint' );

		Main::instance()->reconcile_database_schemas();

		$this->assertSame(
			$stamped,
			(string) get_option( SchemaReconciler::FINGERPRINT_OPTION, '' ),
			'an unchanged declaration must not cause further work'
		);
	}

	private function server( string $type ): int {
		$slug = 'wiring-test-' . wp_generate_password( 6, false, false );

		$id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Reconcile wiring test',
				'server_slug'            => $slug,
				'description'            => 'Seeded by ReconcileWiringTest',
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
}
