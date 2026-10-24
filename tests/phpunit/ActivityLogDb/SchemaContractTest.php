<?php
/**
 * Schema contract for the activity log (Feature 096, T019).
 *
 * Needs a real database: it asserts what MySQL actually created, not what
 * Schema.php declares. Runs under tests/bootstrap-wp.php.
 *
 * This is the B34 guard. A live table can drift from Schema.php while the
 * stored db_version still matches the code version — dbDelta compares column
 * NAMES and not types, so a widened column keeps its old width, INSERT of an
 * over-long value returns false, and the caller reads that as success. Silent
 * write-loss. Asserting presence alone would not catch it, which is why every
 * assertion below checks the WIDTH too.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Schema;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Schema
 * @covers \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table
 */
class SchemaContractTest extends \WP_UnitTestCase {

	/**
	 * Live column metadata, keyed by column name.
	 *
	 * @var array<string, object>
	 */
	private static $live = array();

	public static function set_up_before_class() {
		parent::set_up_before_class();

		global $wpdb;

		Table::instance()->maybe_upgrade();

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );

		foreach ( (array) $rows as $row ) {
			self::$live[ $row->Field ] = $row;
		}
	}

	public function test_the_table_was_actually_created(): void {
		$this->assertTrue( Table::instance()->exists(), 'Table must exist after maybe_upgrade().' );
		$this->assertNotEmpty( self::$live, 'SHOW COLUMNS returned nothing.' );
	}

	/**
	 * Every declared column exists, AT ITS DECLARED WIDTH. The width half is
	 * the point — see the class docblock and B34.
	 */
	public function test_every_declared_column_exists_at_its_declared_width(): void {
		foreach ( ( new Schema() )->columns as $column ) {
			$name = (string) $column->name;

			$this->assertArrayHasKey(
				$name,
				self::$live,
				sprintf( 'Column %s is declared in Schema.php but absent from the live table.', $name )
			);

			$live_type = strtolower( (string) self::$live[ $name ]->Type );

			// MySQL 8.0.16+ drops the display width from integer types, so
			// `bigint(20)` comes back as `bigint unsigned`. Only string types
			// retain a length, and those are the ones that matter here anyway:
			// F029's drift — the reason B34 exists — was varchar widths.
			$is_string_type = (bool) preg_match( '/^(var)?char/', $live_type );

			if ( empty( $column->length ) || ! $is_string_type ) {
				$this->assertStringStartsWith(
					strtolower( (string) $column->type ),
					$live_type,
					sprintf( 'Column %s declares type %s but the live table has %s.', $name, $column->type, $live_type )
				);
				continue;
			}

			$this->assertStringContainsString(
				'(' . $column->length . ')',
				$live_type,
				sprintf(
					'Column %s declares length %s but the live table has %s. This is the B34 drift class: INSERT would return false and the caller would read success.',
					$name,
					$column->length,
					$live_type
				)
			);
		}
	}

	/** The live table must hold nothing Schema.php does not declare. */
	public function test_the_live_table_has_no_undeclared_columns(): void {
		$declared = array();

		foreach ( ( new Schema() )->columns as $column ) {
			$declared[ (string) $column->name ] = true;
		}

		foreach ( array_keys( self::$live ) as $name ) {
			$this->assertArrayHasKey(
				$name,
				$declared,
				sprintf( 'Live column %s is not declared in Schema.php.', $name )
			);
		}
	}

	/** 32 columns: 16 on both event kinds, 12 request-only, 4 ability-only. */
	public function test_column_count_is_the_documented_thirty_two(): void {
		$this->assertCount( 32, ( new Schema() )->columns );
		$this->assertCount( 32, self::$live );
	}

	/**
	 * D-11: the reserved column is `event_source`, never `source`. The adapter
	 * sends its own `source` tag whose value on a toolset call is `ability`,
	 * and a tag-to-column map by name would silently write that here.
	 */
	public function test_the_reserved_column_is_event_source_not_source(): void {
		$this->assertArrayHasKey( 'event_source', self::$live );
		$this->assertArrayNotHasKey(
			'source',
			self::$live,
			'A column named `source` would collide with the adapter tag of the same name (D-11).'
		);
	}

	/** D-10: both the joinable name and the client-facing spelling are stored. */
	public function test_both_the_canonical_and_invoked_names_exist(): void {
		$this->assertArrayHasKey( 'component_name', self::$live );
		$this->assertArrayHasKey( 'invoked_name', self::$live );
	}

	/** Every declared index exists on the live table, under its declared name. */
	public function test_every_declared_index_exists(): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );

		$live = array();

		foreach ( (array) $rows as $row ) {
			$live[ strtolower( (string) $row->Key_name ) ] = true;
		}

		foreach ( ( new Schema() )->indexes as $index ) {
			$expected = strtolower( (string) $index->name );

			$this->assertArrayHasKey(
				$expected,
				$live,
				sprintf( 'Index %s is declared but missing from the live table.', $index->name )
			);
		}
	}

	/** No FULLTEXT in 1.0.0 — a deliberate decision, asserted so it stays one. */
	public function test_no_fulltext_index_was_created(): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );

		foreach ( (array) $rows as $row ) {
			$this->assertNotSame(
				'FULLTEXT',
				strtoupper( (string) $row->Index_type ),
				'1.0.0 ships no FULLTEXT index; search targets two short indexed varchars.'
			);
		}
	}

	/**
	 * The phantom-version guard: if the option survives but the table is
	 * dropped, BerlinDB's needs_upgrade() returns false and install is skipped
	 * forever. maybe_upgrade() must clear the option and reinstall.
	 */
	public function test_phantom_version_guard_reinstalls_a_dropped_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		$this->assertNotFalse(
			get_option( ActivityLogConstants::OPTION_DB_VERSION, false ),
			'Precondition: the version option should still be set after dropping the table.'
		);

		Table::instance()->maybe_upgrade();

		$this->assertTrue(
			Table::instance()->exists(),
			'The phantom-version guard must reinstall a table dropped behind BerlinDB\'s back.'
		);
	}

	/**
	 * Every live index has EXACTLY the columns Schema.php declares, in order.
	 *
	 * `test_every_declared_index_exists()` above checks names only, and a name
	 * is precisely what does not change when a key is re-keyed. Nothing in
	 * BerlinDB, dbDelta or SchemaReconciler ever compares an index's columns
	 * against the Schema, so without this assertion a live table can carry a
	 * differently-keyed index under the right name indefinitely: the queries
	 * stay CORRECT and only get slower, so no test and no user would notice.
	 * That is how the 1.0.0 keys shipped missing their `event_type` prefix.
	 */
	public function test_live_index_columns_match_the_declared_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );

		$live = array();

		foreach ( (array) $rows as $row ) {
			// Seq_in_index is 1-based and orders the key's columns.
			$live[ strtolower( (string) $row->Key_name ) ][ (int) $row->Seq_in_index ] = strtolower( (string) $row->Column_name );
		}

		foreach ( ( new Schema() )->indexes as $index ) {
			$name = strtolower( (string) $index->name );

			// MySQL always names the primary key PRIMARY, whatever the Schema
			// calls it. Map that ONE name rather than falling back for any
			// missing index — a fallback would compare the absent index against
			// PRIMARY's columns and report a mismatch instead of the absence.
			$key = ( 'primary' === $name ) ? 'primary' : $name;

			$this->assertArrayHasKey( $key, $live, sprintf( 'Index %s is missing from the live table.', $index->name ) );

			ksort( $live[ $key ] );

			$declared = array_map( 'strtolower', array_map( 'strval', (array) $index->columns ) );

			$this->assertSame(
				$declared,
				array_values( $live[ $key ] ),
				sprintf( 'Index %s does not have the columns Schema.php declares, in order.', $index->name )
			);
		}
	}

	/**
	 * Each composite key leads with `event_type`.
	 *
	 * The measured reason, not a style rule: one table holds two populations,
	 * so a key without the discriminator matches rows of both kinds. Filtering
	 * server + status examined 742 index entries under `(status, created_at)`
	 * against 239 under `(event_type, status, created_at)` for the identical
	 * result. `ActivityLogController` defaults `event_type` to `request`, so
	 * the predicate is on every list query and nothing wants these un-prefixed.
	 *
	 * `parent` and `created_at` are single-column by design and exempt.
	 */
	public function test_composite_indexes_lead_with_the_discriminator(): void {
		$exempt = array( 'primary', 'parent', 'created_at' );

		$checked = 0;

		foreach ( ( new Schema() )->indexes as $index ) {
			$name    = strtolower( (string) $index->name );
			$columns = array_map( 'strtolower', array_map( 'strval', (array) $index->columns ) );

			if ( in_array( $name, $exempt, true ) || count( $columns ) < 2 ) {
				continue;
			}

			++$checked;

			$this->assertContains(
				'event_type',
				$columns,
				sprintf( 'Composite index %s must include the event_type discriminator.', $index->name )
			);

			// server_type_created leads with server_id on purpose — server is
			// the more selective first column there, and event_type still sits
			// ahead of created_at. Everything else must lead with event_type.
			if ( 'server_type_created' !== $name ) {
				$this->assertSame(
					'event_type',
					$columns[0],
					sprintf( 'Composite index %s must LEAD with event_type, not merely contain it.', $index->name )
				);
			}
		}

		$this->assertSame( 6, $checked, 'Expected 6 composite indexes; update this test if the count changed deliberately.' );
	}

	/**
	 * The 1.1.0 migration re-keys a table that is physically still at 1.0.0.
	 *
	 * A fresh install gets the new keys straight from Schema.php, so every
	 * assertion above passes without the callback existing at all. This is the
	 * only test that exercises the UPGRADE path — the one an existing install
	 * takes.
	 *
	 * It drives `maybe_upgrade()`, NOT the callback directly, because the
	 * interesting failures are all in the dispatch rather than the SQL. Calling
	 * the protected method proves only that the ALTERs are well-formed; it
	 * cannot catch BerlinDB declining to run them, which is exactly what
	 * happened the first time this test was written and is why the
	 * version-absent guard in `Table::maybe_upgrade()` exists.
	 */
	public function test_the_1_1_0_migration_rekeys_a_1_0_0_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// old name => new name.
		$pairs = array(
			'status_created'     => 'type_status_created',
			'component_created'  => 'type_component_created',
			'connection_created' => 'type_connection_created',
			'user_created'       => 'type_user_created',
		);

		// old name => its 1.0.0 columns.
		$old_columns = array(
			'status_created'     => '`status`, `created_at`',
			'component_created'  => '`component_name`, `created_at`',
			'connection_created' => '`connection_type`, `created_at`',
			'user_created'       => '`user_id`, `created_at`',
		);

		// Wind the physical table back to its 1.0.0 index shape.
		foreach ( $pairs as $old_name => $new_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$new_name}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$old_name}` ({$old_columns[ $old_name ]})" );
		}

		$instance = Table::instance();

		$this->assertTrue( $instance->index_exists( 'status_created' ), 'Precondition: the 1.0.0 key should be rebuilt.' );
		$this->assertFalse( $instance->index_exists( 'type_status_created' ), 'Precondition: the 1.1.0 key should be gone.' );

		// Stamp the version back to 1.0.0 and clear BerlinDB's 900-second
		// upgrade lock, which would otherwise make maybe_upgrade() a silent
		// no-op for any run following a recent one.
		update_option( ActivityLogConstants::OPTION_DB_VERSION, '1.0.0' );
		delete_transient( ActivityLogConstants::OPTION_DB_VERSION . '_upgrade_lock' );

		$instance->maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );
		$live = array();

		foreach ( (array) $rows as $row ) {
			$live[ strtolower( (string) $row->Key_name ) ][ (int) $row->Seq_in_index ] = strtolower( (string) $row->Column_name );
		}

		foreach ( $pairs as $old_name => $new_name ) {
			$this->assertArrayNotHasKey( $old_name, $live, sprintf( 'The 1.0.0 key %s should have been dropped.', $old_name ) );
			$this->assertArrayHasKey( $new_name, $live, sprintf( 'The 1.1.0 key %s should have been added.', $new_name ) );

			ksort( $live[ $new_name ] );

			$this->assertSame(
				'event_type',
				array_values( $live[ $new_name ] )[0],
				sprintf( '%s must lead with event_type after the migration.', $new_name )
			);
		}

		$this->assertSame(
			'1.1.0',
			(string) get_option( ActivityLogConstants::OPTION_DB_VERSION ),
			'The migration should stamp the new version once it has actually run.'
		);
	}

	/**
	 * The table name RESOLVES — the bug that made the log record nothing.
	 *
	 * BerlinDB does not read `Query::$table_name` directly. `get_table_name()`
	 * calls `get_table_prefix()`, which returns `$wpdb->{$prefixed_name} ?? ''`
	 * — a property set ONLY by the Table object's constructor. With no Table
	 * constructed the name is the EMPTY STRING, the insert degrades to
	 * `SHOW FULL COLUMNS FROM ``` and fails, `add_item()` returns false, and
	 * the Recorder's A15 catch swallows it. Silent total write-loss.
	 *
	 * It shipped because the only thing constructing the Table was
	 * `Main::reconcile_database_schemas()` on `admin_init` — which fires on an
	 * admin page and NOT on a front-end REST request or on WP-Cron. Every MCP
	 * call is the former and the retention sweep is the latter, so the log
	 * recorded nothing from real traffic while every test passed: the test
	 * bootstrap constructs the Table itself, which is exactly why no assertion
	 * could see it. Hence the unset below — without it this test passes against
	 * the broken code and proves nothing.
	 */
	public function test_the_table_name_resolves_without_admin_init(): void {
		global $wpdb;

		$query    = \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query::instance();
		$expected = $wpdb->prefix . ActivityLogConstants::TABLE;

		// Reproduce a fresh request: $wpdb has never been told the name.
		$reflected = new \ReflectionObject( $query );
		$property  = $reflected->getProperty( 'table_name' );
		$property->setAccessible( true );
		$key = (string) $property->getValue( $query );

		$saved = $wpdb->{$key} ?? null;
		unset( $wpdb->{$key} );

		// Reset the singleton too. Table::instance() caches, so without this the
		// second call returns the already-constructed object and never re-runs
		// the constructor that sets the $wpdb property — the test would fail
		// against CORRECT code. A real request constructs it fresh exactly once,
		// which is what this reproduces.
		$singleton = new \ReflectionProperty( Table::class, 'instance' );
		$singleton->setAccessible( true );
		$singleton->setValue( null, null );

		$this->assertSame(
			'',
			$query->get_table_name(),
			'Precondition: with $wpdb unaware of the table, the name resolves empty — this is the failure mode.'
		);

		// What Main does on every request, admin or not.
		Table::instance();

		$this->assertSame(
			$expected,
			$query->get_table_name(),
			'Constructing the Table must make the name resolvable, or every write silently targets ``.'
		);

		if ( null !== $saved ) {
			$wpdb->{$key} = $saved;
		}
	}

	/**
	 * Main registers the table on EVERY request, not only on `admin_init`.
	 *
	 * The test above proves constructing the Table fixes resolution; this one
	 * proves something actually constructs it outside the admin. Asserted
	 * against Main's source because the constructor cannot be re-run inside a
	 * booted test process — and because what regresses is someone moving this
	 * call back under an `admin_init` hook, which reads as a tidy-up.
	 */
	public function test_main_registers_the_table_outside_admin_init(): void {
		$main = new \ReflectionClass( \AcrossAI_MCP_Manager\Includes\Main::class );
		$body = (string) file_get_contents( $main->getFileName() );

		$this->assertMatchesRegularExpression(
			'/\$this->register_activity_log_table\(\s*\)\s*;/',
			$body,
			'Main::__construct must register the activity log table on every request.'
		);

		$this->assertMatchesRegularExpression(
			'/function register_activity_log_table.*?ActivityLog\\\\Table::instance\(\s*\)/s',
			$body,
			'register_activity_log_table() must construct the Table — that is what teaches $wpdb the name.'
		);
	}

	/**
	 * SC-026 — the filter-to-index map, exceptions included.
	 *
	 * `ActivityLogController::filters_from()` offers ten filters. Seven are
	 * backed by a key that leads with the filtered column (after the
	 * `event_type` discriminator); three are deliberately NOT indexed because
	 * each carries only a handful of distinct values, and the table's indexes
	 * already outweigh its row data roughly 7:3.
	 *
	 * Both halves are asserted. Pinning only the indexed seven would let the
	 * exceptions grow silently; pinning only the exceptions would not catch a
	 * key being dropped. If this test fails because a filter was added, the
	 * question to answer is whether its cardinality justifies a key — not how
	 * to make the list pass.
	 */
	public function test_every_offered_filter_is_indexed_or_a_recorded_exception(): void {
		// filter column => the key expected to serve it.
		$indexed = array(
			'event_type'         => 'type_created',
			'connection_type'    => 'type_connection_created',
			'status'             => 'type_status_created',
			'component_name'     => 'type_component_created',
			'parent_id'          => 'parent',
			'server_id'          => 'server_type_created',
			'user_id'            => 'type_user_created',
		);

		// Low cardinality; served by type_created plus the period bound. SC-026.
		$unindexed = array( 'invoked_via', 'method', 'component_category' );

		$by_name = array();

		foreach ( ( new Schema() )->indexes as $index ) {
			$by_name[ strtolower( (string) $index->name ) ] = array_map(
				'strtolower',
				array_map( 'strval', (array) $index->columns )
			);
		}

		foreach ( $indexed as $column => $key ) {
			$this->assertArrayHasKey( $key, $by_name, sprintf( 'Filter %s expects key %s.', $column, $key ) );
			$this->assertContains(
				$column,
				$by_name[ $key ],
				sprintf( 'Key %s must cover the %s filter it serves.', $key, $column )
			);
		}

		// The exceptions must STAY exceptions, or this test is the place that says so.
		foreach ( $unindexed as $column ) {
			foreach ( $by_name as $key => $columns ) {
				$this->assertNotContains(
					$column,
					$columns,
					sprintf(
						'%s is a recorded SC-026 exception but key %s now covers it. If that is intended, move it into the indexed map and justify the cardinality.',
						$column,
						$key
					)
				);
			}
		}

		// And the map must stay exhaustive against the controller.
		$this->assertSame(
			10,
			count( $indexed ) + count( $unindexed ),
			'ActivityLogController offers ten filters; update this map when that changes.'
		);
	}

	/**
	 * A table at 1.0.0 with NO stored version still gets migrated.
	 *
	 * BerlinDB takes the upgrade branch whenever the table exists, but
	 * `get_pending_upgrades()` returns nothing when `$db_version` is empty — so
	 * it runs no callbacks and then stamps the current version regardless. The
	 * table would keep its 1.0.0 keys while reporting 1.1.0, and every later
	 * boot would agree. `Table::maybe_upgrade()` seeds the oldest version to
	 * close that hole; this asserts the hole stays closed.
	 */
	public function test_a_missing_version_option_does_not_skip_the_migration(): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `type_status_created`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `status_created` (`status`, `created_at`)" );

		// The table exists; the version does not.
		delete_option( ActivityLogConstants::OPTION_DB_VERSION );
		delete_transient( ActivityLogConstants::OPTION_DB_VERSION . '_upgrade_lock' );

		$instance = Table::instance();

		Table::instance()->maybe_upgrade();

		$this->assertTrue(
			$instance->index_exists( 'type_status_created' ),
			'A table with no stored version must still be re-keyed, not merely re-stamped.'
		);
		$this->assertFalse(
			$instance->index_exists( 'status_created' ),
			'The superseded 1.0.0 key must be gone.'
		);
	}
}
