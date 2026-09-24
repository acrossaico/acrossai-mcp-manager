<?php
/**
 * F091 — a migration whose statement fails must not stamp its version.
 *
 * Every `upgrade_to_*()` callback in this plugin used to `return true`
 * unconditionally while its own docblock promised the opposite. A failed
 * statement therefore advanced the version stamp, and because BerlinDB only
 * runs callbacks NEWER than the stamp, the change could never be retried — a
 * second, independent route into exactly the frozen state F091 exists to fix.
 *
 * The fix is deliberately asymmetric, and this file pins both halves:
 *
 *   - DROP / MODIFY callbacks return false on failure. SchemaReconciler never
 *     performs those, so an unstamped version is their only retry.
 *   - ADD-column callbacks keep returning true. The reconciler IS their retry,
 *     and returning false would block every later migration in the chain behind
 *     a statement something else already handles — permanently, on a site whose
 *     database user cannot ALTER.
 *
 * Statements are made to fail by rewriting them through the `query` filter,
 * which is the only way to simulate a permission or engine failure without one.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table as CliAuthLogTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class UpgradeReturnHonestyTest extends WP_UnitTestCase {

	/** @var callable|null */
	private $saboteur = null;

	/** @var array<int, array{0: string, 1: string, 2: string}> */
	private $failures = array();

	public function set_up(): void {
		parent::set_up();

		$this->failures = array();

		add_action(
			'acrossai_mcp_schema_upgrade_failed',
			function ( $table, $version, $error ): void {
				$this->failures[] = array( (string) $table, (string) $version, (string) $error );
			},
			10,
			3
		);

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		if ( null !== $this->saboteur ) {
			remove_filter( 'query', $this->saboteur, 1 );
			$this->saboteur = null;
		}

		// Restore both tables unconditionally — DDL escapes the rollback (B53).
		foreach ( array( MCPServerTable::class, CliAuthLogTable::class ) as $table_class ) {
			$version_key = self::declared( $table_class, 'db_version_key' );

			delete_transient( $version_key . '_upgrade_lock' );
			delete_option( $version_key );

			$table_class::instance()->maybe_upgrade();
		}

		parent::tear_down();
	}

	/**
	 * A DROP that fails must leave the version alone so it can be retried.
	 */
	public function test_failed_drop_does_not_stamp_the_version(): void {
		$table_class = MCPServerTable::class;
		$version_key = self::declared( $table_class, 'db_version_key' );
		$table        = $table_class::instance();

		// Recreate the pre-1.1.7 state: the column the migration must drop.
		global $wpdb;
		$table_name = $wpdb->prefix . 'acrossai_mcp_servers';
		$wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `tools_default_policy` varchar(16) NOT NULL DEFAULT 'expose'" );

		update_option( $version_key, '1.1.6' );
		delete_transient( $version_key . '_upgrade_lock' );

		$this->sabotage( 'DROP COLUMN `tools_default_policy`' );

		$result = $this->invoke( $table, 'upgrade_to_1_1_7' );

		$this->assertFalse( $result, 'a failed DROP must report failure' );
		$this->assertNotEmpty( $this->failures, 'the failure must be observable through the action' );
		$this->assertSame( 'acrossai_mcp_servers', $this->failures[0][0] );
		$this->assertSame( '1.1.7', $this->failures[0][1] );
	}

	/**
	 * A MODIFY that fails must do the same.
	 */
	public function test_failed_modify_does_not_stamp_the_version(): void {
		global $wpdb;

		$table_class = CliAuthLogTable::class;
		$table       = $table_class::instance();
		$table_name  = $wpdb->prefix . 'acrossai_mcp_cli_auth_logs';

		// Narrow a column so the migration has work to do.
		$wpdb->query( "ALTER TABLE `{$table_name}` MODIFY COLUMN `status` varchar(20) NOT NULL DEFAULT 'pending'" );

		$this->sabotage( 'MODIFY COLUMN `status`' );

		$result = $this->invoke( $table, 'upgrade_to_1_0_1' );

		$this->assertFalse( $result, 'a failed MODIFY must report failure' );
		$this->assertNotEmpty( $this->failures, 'the failure must be observable through the action' );
		$this->assertSame( 'acrossai_mcp_cli_auth_logs', $this->failures[0][0] );
	}

	/**
	 * The asymmetry, pinned. An ADD that fails must NOT report failure, because
	 * blocking the whole migration chain is worse than a column the reconciler
	 * will add moments later anyway.
	 */
	public function test_failed_add_still_reports_success_so_the_chain_is_not_blocked(): void {
		global $wpdb;

		$table_class = MCPServerTable::class;
		$table       = $table_class::instance();
		$table_name  = $wpdb->prefix . 'acrossai_mcp_servers';

		$wpdb->query( "ALTER TABLE `{$table_name}` DROP COLUMN `override_abilities_permission`" );

		$this->sabotage( 'ADD COLUMN `override_abilities_permission`' );

		$this->assertTrue(
			$this->invoke( $table, 'upgrade_to_1_1_2' ),
			'an ADD-column migration must not stall the chain; SchemaReconciler is its retry'
		);
	}

	/**
	 * Break any statement containing $needle by rewriting it into invalid SQL.
	 */
	private function sabotage( string $needle ): void {
		$this->saboteur = static function ( $query ) use ( $needle ) {
			if ( is_string( $query ) && false !== strpos( $query, $needle ) ) {
				return 'ALTER TABLE `this_table_does_not_exist_f091` DROP COLUMN `nope`';
			}

			return $query;
		};

		add_filter( 'query', $this->saboteur, 1 );
	}

	/**
	 * Invoke a migration callback with database errors suppressed.
	 *
	 * These tests make statements fail ON PURPOSE, and `$wpdb` prints an HTML
	 * error block for each one, which this suite counts as unexpected output.
	 *
	 * Worth recording what that reveals: the migration callbacks do NOT suppress
	 * errors themselves, so on a live site with display enabled a genuinely
	 * failing migration would print raw SQL into wp-admin. `SchemaReconciler`
	 * suppresses around its own statements for exactly that reason. Bringing the
	 * seven existing callbacks in line is a worthwhile follow-up and deliberately
	 * out of scope here — this feature does not touch five of them.
	 *
	 * @return bool
	 */
	private function invoke( object $table, string $method ): bool {
		global $wpdb;

		$reflection = new ReflectionMethod( $table, $method );
		$reflection->setAccessible( true );

		$suppress = $wpdb->suppress_errors( true );
		$result   = (bool) $reflection->invoke( $table );
		$wpdb->suppress_errors( $suppress );

		return $result;
	}

	private static function declared( string $class, string $property ): string {
		$value = ( new ReflectionClass( $class ) )->getDefaultProperties()[ $property ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
