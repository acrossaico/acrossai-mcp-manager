<?php
/**
 * F091 — add declared columns that no migration will ever deliver.
 *
 * BerlinDB decides whether to migrate by comparing one option against the
 * declared `$version`. Two behaviours in the vendored library combine to strand
 * a table permanently:
 *
 *   1. PHANTOM STAMP. `Table::get_pending_upgrades()` returns nothing whenever
 *      the recorded version is EMPTY, and `Table::upgrade()` then calls
 *      `set_db_version()` and reports success having run ZERO callbacks. Any
 *      site whose table already existed but whose BerlinDB-era option key did
 *      not — every install created by the pre-F011 hand-rolled installer, which
 *      tracked `acrossai_mcp_manager_db_version` — is stamped at whatever
 *      version the release it landed on declared, and every callback at or
 *      below that version is skipped FOREVER. No later version bump can reach
 *      it, because the stamp already matches.
 *
 *   2. NO AUTO-DIFF. `Table::create()` is a raw `CREATE TABLE` invoked only
 *      from `install()` when the table is ABSENT, and nothing anywhere compares
 *      a `Schema` against a live table. A column added to a Schema without a
 *      paired `$upgrades` entry therefore reaches fresh installs ONLY.
 *
 * Both were observed in production. See specs/091-schema-drift-reconciliation/
 * research.md for the per-release version map that reconstructs an affected
 * site's history exactly from its surviving column set.
 *
 * This service is the backstop for both: it compares each table's DECLARED
 * columns against its LIVE columns and adds whatever is missing, so a table
 * converges on its declaration regardless of what the version stamp claims.
 *
 * ADD-ONLY, and that is the whole safety argument (D28 amendment):
 *   - Never DROP. A live-minus-declared column cannot be distinguished from one
 *     another plugin or the operator added, so dropping risks destroying data
 *     this plugin does not own.
 *   - Never MODIFY. Narrowing or retyping can truncate stored values. Adding a
 *     missing column cannot lose anything.
 *   - Width and type divergence are REPORTED through
 *     `acrossai_mcp_schema_drift_detected`, never acted on.
 *
 * `bin/verify-f021-gates.sh` asserts this file contains no destructive DDL
 * keyword, so the property is enforced mechanically rather than by convention.
 *
 * No DDL is hand-written. `Column::get_create_string()` emits exactly the
 * fragment that follows `ADD COLUMN`, which means the physical column can never
 * disagree with its declaration — the failure mode every hand-written `ALTER`
 * string in `MCPServer\Table` is exposed to.
 *
 * A11 pure-service exception (stateless, static-only — no singleton, no ctor).
 * See docs/memory/ARCHITECTURE.md §A11.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database
 * @since      0.3.7 (F091)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use ReflectionClass;

defined( 'ABSPATH' ) || exit;

/**
 * Declared-versus-live column reconciler. Adds what is missing; reports the rest.
 *
 * @since 0.3.7 (F091)
 */
final class SchemaReconciler {

	/**
	 * Records the declared column set most recently reconciled.
	 *
	 * Deleting it forces a full re-check, which is the documented recovery
	 * action (README), mirroring `LegacyOAuthCleanup::DONE_OPTION`.
	 *
	 * Deliberately NOT a one-shot done-flag. A done-flag burns its single
	 * opportunity on whichever page load happens to come first and then disables
	 * the guarantee forever, and drift can arrive in any future release.
	 */
	public const FINGERPRINT_OPTION = 'acrossai_mcp_schema_fingerprint';

	/**
	 * Guards one reconciliation pass against a concurrent one.
	 *
	 * BerlinDB's own `{$db_version_key}_upgrade_lock` protects `maybe_upgrade()`
	 * and does NOT cover this pass. `ADD COLUMN IF NOT EXISTS` is not an option
	 * either — that syntax is MariaDB-only and MySQL 8 rejects it.
	 */
	public const LOCK_TRANSIENT = 'acrossai_mcp_schema_reconcile_lock';

	/**
	 * Lock lifetime. Short: a pass is five `SHOW COLUMNS` reads plus, at most,
	 * a handful of `ALTER`s. Long enough to cover a slow one, short enough that
	 * a fatal mid-pass cannot wedge reconciliation for long.
	 */
	public const LOCK_SECONDS = 60;

	/**
	 * Column types MySQL refuses to give a literal DEFAULT (errno 1101).
	 *
	 * Exactly the set the error message names — "BLOB, TEXT, GEOMETRY or JSON" —
	 * and deliberately NOT `char` / `varchar`, which BerlinDB's `is_text()`
	 * lumps in with them but which accept defaults without complaint.
	 *
	 * @var string[]
	 */
	public const DEFAULTLESS_TYPES = array(
		'TINYTEXT',
		'TEXT',
		'MEDIUMTEXT',
		'LONGTEXT',
		'TINYBLOB',
		'BLOB',
		'MEDIUMBLOB',
		'LONGBLOB',
		'JSON',
		'GEOMETRY',
		'POINT',
		'LINESTRING',
		'POLYGON',
		'MULTIPOINT',
		'MULTILINESTRING',
		'MULTIPOLYGON',
		'GEOMETRYCOLLECTION',
	);

	/**
	 * Per-request fingerprint memo, keyed by blog id.
	 *
	 * Keyed rather than scalar because `Table::switch_blog()` mutates the table
	 * name on the singleton mid-request, so a memo shared across a
	 * `switch_to_blog()` would be stale.
	 *
	 * @var array<int, string>
	 */
	private static $fingerprints = array();

	/**
	 * Reconcile every table whose declaration has changed since the last pass.
	 *
	 * Short-circuits on a matching fingerprint, so the steady-state cost is one
	 * non-autoloaded option read per administrative page load.
	 *
	 * The fingerprint is written LAST and ONLY when no `ALTER` failed, so a
	 * transient failure retries on the next page load instead of being recorded
	 * as success — the same discipline as the backfills' done-flags, with the
	 * added condition that a pass which failed is not a pass that happened.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Table[] $tables Tables to reconcile.
	 * @return array<string, string[]> Un-prefixed table stem => columns created.
	 */
	public static function maybe_reconcile( array $tables ): array {
		$created = array();

		if ( array() === $tables ) {
			return $created;
		}

		$fingerprint = self::fingerprint( $tables );

		if ( (string) get_option( self::FINGERPRINT_OPTION, '' ) === $fingerprint ) {
			return $created;
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return $created;
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS );

		$unhealed  = array();
		$divergent = array();
		$failed    = false;

		try {
			foreach ( $tables as $table ) {
				if ( ! $table instanceof Table ) {
					continue;
				}

				$stem = self::declared_property( $table, 'name' );

				if ( '' === $stem ) {
					continue;
				}

				$report = self::inspect( $table );

				if ( array() !== $report['created'] ) {
					$created[ $stem ] = $report['created'];
				}

				if ( array() !== $report['unhealed'] ) {
					$unhealed[ $stem ] = $report['unhealed'];
				}

				if ( array() !== $report['divergent'] ) {
					$divergent[ $stem ] = $report['divergent'];
				}

				$failed = $failed || $report['failed'];
			}
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}

		if ( array() !== $unhealed || array() !== $divergent ) {
			/**
			 * Fires when a reconciliation pass found drift it will not repair.
			 *
			 * Purely informational — nothing in this plugin subscribes. Both
			 * arrays are non-empty when this fires, and table names are
			 * un-prefixed stems so a subscriber need not know the site prefix.
			 *
			 * @since 0.3.7 (F091)
			 *
			 * @param array<string, string[]> $unhealed  Stem => declared columns missing but not added.
			 * @param array<string, array<string, array{declared: string, live: string}>> $divergent Stem => column => differing descriptions.
			 */
			do_action( 'acrossai_mcp_schema_drift_detected', $unhealed, $divergent );
		}

		if ( ! $failed ) {
			update_option( self::FINGERPRINT_OPTION, $fingerprint, false );
		}

		return $created;
	}

	/**
	 * Add every safely-addable declared column this table is missing.
	 *
	 * Public so a single table can be reconciled in isolation, which is how the
	 * test suite exercises it.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Table $table The table to reconcile.
	 * @return string[] Names of columns actually created.
	 */
	public static function reconcile( Table $table ): array {
		$report = self::inspect( $table );

		return $report['created'];
	}

	/**
	 * Whether a declared column can be added to a table that already has rows.
	 *
	 * Derived from the column's own create string and type predicates rather
	 * than from a maintained exclusion list, so it stays correct as columns are
	 * added (Constitution VI — a list would be a second place the schema is
	 * described, and it would drift).
	 *
	 * Each rule prevents a specific MySQL error, verified against 8.0.35:
	 *
	 *   - `auto_increment` -> errno 1075, "there can be only one auto column and
	 *     it must be defined as a key". `ADD COLUMN` adds no key, so every
	 *     auto-increment primary key is excluded.
	 *   - TEXT / BLOB / JSON / GEOMETRY carrying a default -> errno 1101, "BLOB,
	 *     TEXT, GEOMETRY or JSON column can't have a default value". BerlinDB
	 *     emits `default ''` for these, so its own create string is invalid as an
	 *     `ADD COLUMN` fragment for them.
	 *
	 *     Matched against {@see self::DEFAULTLESS_TYPES} rather than
	 *     `Column::is_text()`, which also answers true for `char` and `varchar`
	 *     — types that accept a default perfectly well. Using the predicate here
	 *     would exclude almost every string column in the plugin and quietly
	 *     reduce the reconciler to numeric columns only.
	 *   - The zero date -> errno 1067, "Invalid default value". WordPress's
	 *     `wpdb::set_sql_mode()` happens to strip `NO_ZERO_DATE`, so this would
	 *     usually succeed — but "usually" depends on a host not restoring strict
	 *     mode, and a repair whose correctness depends on session state is not a
	 *     repair. Excluded deliberately.
	 *
	 * Anything excluded here is REPORTED through the drift action rather than
	 * silently ignored.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Column $column Declared column.
	 * @return bool True when `ADD COLUMN` may be attempted.
	 */
	public static function is_addable( Column $column ): bool {
		$create = trim( $column->get_create_string() );

		if ( '' === $create ) {
			return false;
		}

		if ( false !== stripos( $create, 'auto_increment' ) ) {
			return false;
		}

		$has_default = ( false !== stripos( $create, ' default ' ) );

		if ( $has_default && in_array( strtoupper( (string) $column->type ), self::DEFAULTLESS_TYPES, true ) ) {
			return false;
		}

		if ( false !== stripos( $create, "default '0000-00-00" ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build the fingerprint that decides whether a pass is needed at all.
	 *
	 * Covers the declared COLUMN SET, not merely the declared versions. That
	 * distinction is load-bearing: the second defect this feature exists to fix
	 * is "a column added with no version bump", so a version-only fingerprint
	 * would skip exactly the drift it is meant to catch — reproducing the bug
	 * inside its own fix.
	 *
	 * The plugin version is included so every release forces exactly one full
	 * pass, which catches drift introduced by something other than this code.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Table[] $tables Tables to fingerprint.
	 * @return string
	 */
	public static function fingerprint( array $tables ): string {
		$blog_id = get_current_blog_id();

		if ( isset( self::$fingerprints[ $blog_id ] ) ) {
			return self::$fingerprints[ $blog_id ];
		}

		$parts = array(
			defined( 'ACROSSAI_MCP_MANAGER_VERSION' ) ? (string) ACROSSAI_MCP_MANAGER_VERSION : '',
		);

		foreach ( $tables as $table ) {
			if ( ! $table instanceof Table ) {
				continue;
			}

			$columns = array();

			foreach ( self::declared_columns( $table ) as $column ) {
				$columns[] = $column->get_create_string();
			}

			sort( $columns );

			$parts[] = self::declared_property( $table, 'name' )
				. '|' . self::declared_property( $table, 'version' )
				. '|' . implode( ',', $columns );
		}

		$fingerprint = md5( implode( '||', $parts ) );

		self::$fingerprints[ $blog_id ] = $fingerprint;

		return $fingerprint;
	}

	/**
	 * Drop the per-request fingerprint memo.
	 *
	 * Production API rather than a test-only hook: a caller that legitimately
	 * changes the declared set mid-request (only the test suite does today) must
	 * be able to invalidate it. Named for what it does, per D53.
	 *
	 * @since  0.3.7 (F091)
	 * @return void
	 */
	public static function reset_request_cache(): void {
		self::$fingerprints = array();
	}

	/**
	 * Compare one table and apply what is safe.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Table $table The table to inspect.
	 * @return array{created: string[], unhealed: string[], divergent: array<string, array{declared: string, live: string}>, failed: bool}
	 */
	private static function inspect( Table $table ): array {
		$report = array(
			'created'   => array(),
			'unhealed'  => array(),
			'divergent' => array(),
			'failed'    => false,
		);

		// A table that does not exist is BerlinDB's job to install, not ours.
		if ( ! $table->exists() ) {
			return $report;
		}

		$declared = self::declared_columns( $table );

		if ( array() === $declared ) {
			return $report;
		}

		$live = Schema::from_table( self::table_name( $table ) )->get_columns();

		// An empty live set means the introspection failed or the table vanished
		// between the existence check and here. It NEVER means "every column is
		// missing" — without this guard the loop below would emit one ALTER per
		// declared column against a table it cannot see.
		if ( array() === $live ) {
			return $report;
		}

		$live_by_name = array();

		foreach ( $live as $live_column ) {
			$live_by_name[ strtolower( (string) $live_column->name ) ] = $live_column;
		}

		foreach ( $declared as $column ) {
			$name = (string) $column->name;
			$key  = strtolower( $name );

			if ( isset( $live_by_name[ $key ] ) ) {
				$difference = self::divergence( $column, $live_by_name[ $key ] );

				if ( null !== $difference ) {
					$report['divergent'][ $name ] = $difference;
				}

				continue;
			}

			if ( ! self::is_addable( $column ) ) {
				$report['unhealed'][] = $name;
				continue;
			}

			if ( self::add_column( $table, $column ) ) {
				$report['created'][] = $name;
				continue;
			}

			$report['unhealed'][] = $name;
			$report['failed']     = true;
		}

		return $report;
	}

	/**
	 * Execute one `ADD COLUMN`.
	 *
	 * Errors are suppressed for the duration: on a live site with display
	 * enabled a failed statement would otherwise print an HTML error block into
	 * wp-admin, and the test suite fails on unexpected output.
	 *
	 * A `false` return is re-checked against `column_exists()` before being
	 * treated as failure, so losing a race to a concurrent pass counts as the
	 * success it effectively is.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Table  $table  Target table.
	 * @param  Column $column Declared column to add.
	 * @return bool True when the column exists afterwards.
	 */
	private static function add_column( Table $table, Column $column ): bool {
		global $wpdb;

		$table_name = self::table_name( $table );
		$fragment   = $column->get_create_string();

		$suppress = $wpdb->suppress_errors( true );

		/*
		 * DDL cannot be prepared: identifiers are not placeholders, and the
		 * column definition is a DDL fragment rather than a value. Both halves
		 * are plugin-owned and no request data reaches this statement — the
		 * table name is $wpdb->prefix plus a hardcoded stem declared on the
		 * Table class, and the fragment is generated by the Column object from
		 * its own declaration. Same sanctioned form as every $upgrades callback
		 * in this namespace (D28).
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN {$fragment}" );

		$wpdb->suppress_errors( $suppress );

		// $wpdb::query() returns true for DDL, never a row count, so only a
		// strict false is a failure.
		if ( false !== $result ) {
			return true;
		}

		return (bool) $table->column_exists( (string) $column->name );
	}

	/**
	 * Report a type or width difference between a declared and a live column.
	 *
	 * Length is compared ONLY when both sides report one. MySQL 8.0.19 dropped
	 * integer display width from `SHOW COLUMNS`, so a declared `tinyint(1)`
	 * meets a live `tinyint` with no length at all — without this guard every
	 * boolean column on every modern server would be reported as drifted.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Column $declared Declared column.
	 * @param  Column $live     Live column.
	 * @return array{declared: string, live: string}|null Null when they agree.
	 */
	private static function divergence( Column $declared, Column $live ): ?array {
		$declared_type = strtoupper( (string) $declared->type );
		$live_type     = strtoupper( (string) $live->type );

		$declared_length = (int) $declared->length;
		$live_length     = (int) $live->length;

		$type_differs = ( $declared_type !== $live_type );

		$length_differs = ( 0 !== $declared_length )
			&& ( 0 !== $live_length )
			&& ( $declared_length !== $live_length );

		if ( ! $type_differs && ! $length_differs ) {
			return null;
		}

		return array(
			'declared' => $declared_type . ( 0 !== $declared_length ? "({$declared_length})" : '' ),
			'live'     => $live_type . ( 0 !== $live_length ? "({$live_length})" : '' ),
		);
	}

	/**
	 * Read a table's DECLARED columns.
	 *
	 * Reads the raw `$columns` declaration by reflection and builds the Column
	 * objects directly, rather than reaching for `$table->schema_object` or
	 * constructing the Schema. Two separate reasons:
	 *
	 *   1. `schema_object` is private and reachable only through BerlinDB's
	 *      magic accessor, which static analysis cannot verify — and that
	 *      accessor prefers a `get_{$key}()` method when one exists, which is
	 *      exactly the trap that makes `$table->version` return the INSTALLED
	 *      version instead of the declared one.
	 *   2. Constructing the Schema also constructs its Index objects, and
	 *      `MCPServerMeta`'s declares a `length` key that BerlinDB's Index does
	 *      not define. On PHP 8.2+ that raises a dynamic-property deprecation,
	 *      which prints into output and makes any test touching it RISKY under
	 *      this suite's `beStrictAboutOutputDuringTests`. We need columns only,
	 *      so building indexes at all is both wasteful and harmful.
	 *
	 * Mirrors `DefaultServerSeeder::schema_columns()`, which reads the same
	 * declaration by reflection for the same kind of reason.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Table $table Table to read.
	 * @return Column[]
	 */
	private static function declared_columns( Table $table ): array {
		$class = self::declared_property( $table, 'schema' );

		if ( '' === $class || ! class_exists( $class ) ) {
			return array();
		}

		$declared = ( new ReflectionClass( $class ) )->getDefaultProperties()['columns'] ?? array();

		if ( ! is_array( $declared ) ) {
			return array();
		}

		$columns = array();

		foreach ( $declared as $args ) {
			if ( $args instanceof Column ) {
				$columns[] = $args;
				continue;
			}

			if ( is_array( $args ) ) {
				$columns[] = new Column( $args );
			}
		}

		return $columns;
	}

	/**
	 * Compose the prefixed table name from declared values.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Table $table Table to name.
	 * @return string
	 */
	private static function table_name( Table $table ): string {
		global $wpdb;

		$stem = self::declared_property( $table, 'name' );

		if ( '' === $stem ) {
			return '';
		}

		$reflection = new ReflectionClass( $table );
		$defaults   = $reflection->getDefaultProperties();
		$is_global  = ! empty( $defaults['global'] );

		return ( $is_global ? $wpdb->base_prefix : $wpdb->prefix ) . $stem;
	}

	/**
	 * Read a declared (code-side) property default off a Table subclass.
	 *
	 * @since  0.3.7 (F091)
	 * @param  Table  $table Table to read.
	 * @param  string $key   Property name.
	 * @return string Empty string when absent or non-scalar.
	 */
	private static function declared_property( Table $table, string $key ): string {
		$reflection = new ReflectionClass( $table );
		$defaults   = $reflection->getDefaultProperties();

		$value = $defaults[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
