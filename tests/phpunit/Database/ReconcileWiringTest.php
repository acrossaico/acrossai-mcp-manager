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
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
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

	/**
	 * THE FUTURE-UPGRADER CASE.
	 *
	 * A site still on a pre-BerlinDB build that updates STRAIGHT to a release
	 * carrying F091 walks into the phantom stamp on its first admin request:
	 * its table exists, the BerlinDB-era version option does not, so
	 * `maybe_upgrade()` stamps the declared version having run zero callbacks
	 * and every migration is skipped.
	 *
	 * The question this pins is whether such a site is ever USER-VISIBLY broken.
	 * It must not be: `reconcile_database_schemas()` runs the reconciler in the
	 * same request, immediately after the stamp, so the drift is created and
	 * healed before anything reads it. Ordering here is the whole answer.
	 */
	public function test_a_site_upgrading_after_this_release_is_healed_in_the_same_request(): void {
		global $wpdb;

		$table       = $wpdb->prefix . 'acrossai_mcp_servers';
		$version_key = 'acrossai_mcp_servers_db_version';

		// A SEEDED server — the plugin knows what type this slug should be, from
		// its own declaration, so this one is recoverable.
		$seeded_slug = '';

		foreach ( ServerTypes::seeded_servers() as $slug => $seeded ) {
			if ( 'acrossai' === ( $seeded['type'] ?? '' ) ) {
				$seeded_slug = (string) $slug;
				break;
			}
		}

		if ( '' === $seeded_slug ) {
			$this->markTestSkipped( 'no seeded acrossai server is declared' );
		}

		$seeded_id = $this->server( 'acrossai', $seeded_slug );

		// An OPERATOR-created server. Nothing anywhere records what type this
		// was, so it is NOT recoverable — see the assertion at the end.
		$operator_id = $this->server( 'acrossai' );

		// The pre-BerlinDB shape: old columns gone, INCLUDING server_type.
		foreach ( array_merge( self::DRIFTED, array( 'server_type' ) ) as $column ) {
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" );
		}

		// A pre-F011 install tracked `acrossai_mcp_manager_db_version`; the
		// BerlinDB key simply does not exist. That absence IS the trigger.
		delete_option( $version_key );
		delete_transient( $version_key . '_upgrade_lock' );
		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		SchemaReconciler::reset_request_cache();

		$this->assertSame( '', (string) get_option( $version_key, '' ), 'setup: no BerlinDB version stamp' );

		// ONE administrative page load.
		Main::instance()->reconcile_database_schemas();

		// The phantom stamp still happens — we did not fix BerlinDB.
		$declared = (string) ( new ReflectionClass( MCPServerTable::class ) )->getDefaultProperties()['version'];
		$this->assertSame(
			$declared,
			(string) get_option( $version_key, '' ),
			'BerlinDB still stamps the declared version having run nothing — unchanged, and fine'
		);

		// ...but the table is whole anyway, because the reconciler follows it.
		foreach ( array_merge( self::DRIFTED, array( 'server_type' ) ) as $column ) {
			$this->assertTrue(
				MCPServerTable::instance()->column_exists( $column ),
				"{$column} must be healed in the same request that stamped over it"
			);
		}

		$seeded = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $seeded_id ), ARRAY_A );

		$this->assertSame(
			'acrossai',
			(string) $seeded['server_type'],
			'a seeded server is retyped from the plugin\'s own declaration'
		);

		$this->assertSame(
			array( '0', '0', '0' ),
			array(
				(string) $seeded['tool_discover_abilities'],
				(string) $seeded['tool_get_ability_info'],
				(string) $seeded['tool_execute_ability'],
			),
			'and never becomes visibly wrong, even for one request'
		);

		/*
		 * THE LIMIT OF WHAT IS RECOVERABLE, asserted rather than left implicit.
		 *
		 * An operator-created server falls back to the column default,
		 * `mcp-adapter`, and therefore to the three protocol tools. That is not
		 * a defect: the column that stored its type did not exist, so the type
		 * was never recorded anywhere and nothing can deduce it.
		 *
		 * It is also not a population that can be harmed. Server types arrived
		 * in 0.3.4; a site missing the `server_type` column has, by definition,
		 * never run a build that had them, so every server it owns predates the
		 * concept and `mcp-adapter` is the historically correct answer.
		 *
		 * If that ever stops being true — a future release that drops and
		 * re-adds the column, say — this assertion is the one that will notice.
		 */
		$operator = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $operator_id ), ARRAY_A );

		$this->assertSame(
			'mcp-adapter',
			(string) $operator['server_type'],
			'an operator-created server\'s type is unrecoverable and falls back to the default'
		);
	}

	private function server( string $type, string $slug = '' ): int {
		$slug = ( '' !== $slug ) ? $slug : 'wiring-test-' . wp_generate_password( 6, false, false );

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
