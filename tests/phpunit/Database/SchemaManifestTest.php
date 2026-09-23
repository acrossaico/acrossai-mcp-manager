<?php
/**
 * F091 — the frozen column manifest.
 *
 * F091's reconciler relaxes D28 for ADDITIONS: a new column needs only its
 * Schema entry, because the reconciler delivers it to every existing site. This
 * test is the other half of that bargain. It fails the build on the changes the
 * reconciler deliberately will NOT perform — a column removed, narrowed, or
 * retyped — because those still require the full three-part migration contract
 * and would otherwise ship as silent, permanent drift. Exactly how the four
 * PKCE columns on the authentication-log table came to exist on fresh installs
 * only.
 *
 * Columns ABSENT from the manifest are ignored on purpose. Additions are legal
 * and unremarkable now, and a gate that fires on legitimate changes becomes
 * noise people learn to ignore — the argument `bin/verify-f021-gates.sh` makes
 * about its own gates.
 *
 * WHY A TEST AND NOT A GREP GATE. B34's prevention recipe asked for a CI grep on
 * `Schema.php` diffs. It cannot be one: "was this column narrowed" is a DIFF
 * predicate, and `verify-f021-gates.sh` runs whole-repo content greps against a
 * `fetch-depth: 1` checkout with no merge base — it also runs on `push: main`,
 * where "the diff" is undefined. A frozen manifest gives the identical guarantee
 * with no diff at all, and a far better failure message.
 *
 * WP-free by design, like ColumnWidthInvariantTest: it reads declarations, never
 * a database.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SchemaManifestTest extends TestCase {

	/**
	 * What every declared column looked like when this manifest was frozen.
	 *
	 * Shape: table stem => column name => array( TYPE, length, allow_null ).
	 * A length of 0 means the declaration carries none.
	 *
	 * ADDING a column? Nothing to do here — the reconciler ships it.
	 * REMOVING, NARROWING or RETYPING one? Ship the D28 three-part contract
	 * (version bump + `$upgrades` callback) and update this manifest in the
	 * same commit.
	 *
	 * @var array<string, array<string, array{0: string, 1: int, 2: bool}>>
	 */
	private const MANIFEST = array(
		'acrossai_mcp_servers'          => array(
			'id'                            => array( 'BIGINT', 20, false ),
			'server_name'                   => array( 'VARCHAR', 255, false ),
			'server_slug'                   => array( 'VARCHAR', 255, false ),
			'description'                   => array( 'VARCHAR', 500, false ),
			'is_enabled'                    => array( 'TINYINT', 1, false ),
			'registered_from'               => array( 'VARCHAR', 50, false ),
			'server_route_namespace'        => array( 'VARCHAR', 100, false ),
			'server_route'                  => array( 'VARCHAR', 255, false ),
			'server_version'                => array( 'VARCHAR', 50, false ),
			'tool_discover_abilities'       => array( 'TINYINT', 1, false ),
			'tool_get_ability_info'         => array( 'TINYINT', 1, false ),
			'tool_execute_ability'          => array( 'TINYINT', 1, false ),
			'override_abilities_permission' => array( 'TINYINT', 1, false ),
			'abilities_default_policy'      => array( 'VARCHAR', 16, false ),
			'server_type'                   => array( 'VARCHAR', 32, false ),
			'created_at'                    => array( 'DATETIME', 0, false ),
		),
		'acrossai_mcp_server_tools'     => array(
			'id'           => array( 'BIGINT', 20, false ),
			'server_id'    => array( 'BIGINT', 20, false ),
			'ability_slug' => array( 'VARCHAR', 191, false ),
			'created_at'   => array( 'DATETIME', 0, false ),
			'updated_at'   => array( 'DATETIME', 0, false ),
		),
		'acrossai_mcp_server_abilities' => array(
			'id'           => array( 'BIGINT', 20, false ),
			'server_id'    => array( 'BIGINT', 20, false ),
			'ability_slug' => array( 'VARCHAR', 191, false ),
			'is_exposed'   => array( 'TINYINT', 1, false ),
			'created_at'   => array( 'DATETIME', 0, false ),
			'updated_at'   => array( 'DATETIME', 0, false ),
		),
		'acrossai_mcp_servers_meta'     => array(
			'meta_id'    => array( 'BIGINT', 20, false ),
			'server_id'  => array( 'BIGINT', 20, false ),
			'meta_key'   => array( 'VARCHAR', 255, true ),
			'meta_value' => array( 'LONGTEXT', 0, true ),
		),
		'acrossai_mcp_cli_auth_logs'    => array(
			'id'                    => array( 'BIGINT', 20, false ),
			'server_id'             => array( 'BIGINT', 20, false ),
			'server_slug'           => array( 'VARCHAR', 255, false ),
			'user_id'               => array( 'BIGINT', 20, false ),
			'status'                => array( 'VARCHAR', 32, false ),
			'failure_code'          => array( 'VARCHAR', 64, false ),
			'auth_code_hash'        => array( 'CHAR', 64, false ),
			'app_password_uuid'     => array( 'VARCHAR', 36, false ),
			'redirect_uri'          => array( 'VARCHAR', 500, false ),
			'code_challenge'        => array( 'CHAR', 43, false ),
			'code_challenge_method' => array( 'VARCHAR', 16, false ),
			'scope'                 => array( 'VARCHAR', 255, false ),
			'approved_at'           => array( 'DATETIME', 0, true ),
			'completed_at'          => array( 'DATETIME', 0, true ),
			'created_at'            => array( 'DATETIME', 0, false ),
		),
	);

	/**
	 * Schema classes, keyed by the table stem the manifest uses.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMAS = array(
		'acrossai_mcp_servers'          => 'AcrossAI_MCP_Manager\Includes\Database\MCPServer\Schema',
		'acrossai_mcp_server_tools'     => 'AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Schema',
		'acrossai_mcp_server_abilities' => 'AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Schema',
		'acrossai_mcp_servers_meta'     => 'AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Schema',
		'acrossai_mcp_cli_auth_logs'    => 'AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Schema',
	);

	private const REMEDY = "\n\nThe reconciler does NOT perform this change. Ship the D28 three-part contract — bump \$version and add a paired \$upgrades callback on the Table — and update SchemaManifestTest::MANIFEST in the same commit.";

	/**
	 * Each test below collects every offender and asserts once, rather than
	 * asserting inside the loop. Two reasons: a table with nothing to check
	 * still performs an assertion (this suite fails tests that assert nothing),
	 * and a reviewer sees every offending column at once instead of only the
	 * first.
	 *
	 * @dataProvider provideTables
	 */
	public function test_no_manifest_column_was_removed( string $stem ): void {
		$declared = $this->declared( $stem );
		$removed  = array();

		foreach ( self::MANIFEST[ $stem ] as $name => $frozen ) {
			if ( ! isset( $declared[ $name ] ) ) {
				$removed[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$removed,
			"{$stem} lost declared column(s): " . implode( ', ', $removed ) . self::REMEDY
		);
	}

	/**
	 * @dataProvider provideTables
	 */
	public function test_no_manifest_column_changed_type( string $stem ): void {
		$declared = $this->declared( $stem );
		$changed  = array();

		foreach ( self::MANIFEST[ $stem ] as $name => $frozen ) {
			if ( ! isset( $declared[ $name ] ) ) {
				continue; // Reported by the removal test; do not double-fail.
			}

			if ( $frozen[0] !== $declared[ $name ][0] ) {
				$changed[] = "{$name} ({$frozen[0]} -> {$declared[$name][0]})";
			}
		}

		$this->assertSame(
			array(),
			$changed,
			"{$stem} retyped column(s): " . implode( ', ', $changed ) . self::REMEDY
		);
	}

	/**
	 * Widening is fine — a wider column loses nothing. Narrowing truncates.
	 *
	 * @dataProvider provideTables
	 */
	public function test_no_manifest_column_was_narrowed( string $stem ): void {
		$declared = $this->declared( $stem );
		$narrowed = array();

		foreach ( self::MANIFEST[ $stem ] as $name => $frozen ) {
			if ( ! isset( $declared[ $name ] ) ) {
				continue;
			}

			if ( $declared[ $name ][1] < $frozen[1] ) {
				$narrowed[] = "{$name} ({$frozen[1]} -> {$declared[$name][1]})";
			}
		}

		$this->assertSame(
			array(),
			$narrowed,
			"{$stem} narrowed column(s), which truncates stored values: " . implode( ', ', $narrowed ) . self::REMEDY
		);
	}

	/**
	 * Loosening NOT NULL is safe; tightening it rejects existing rows.
	 *
	 * @dataProvider provideTables
	 */
	public function test_no_manifest_column_tightened_nullability( string $stem ): void {
		$declared  = $this->declared( $stem );
		$tightened = array();

		foreach ( self::MANIFEST[ $stem ] as $name => $frozen ) {
			if ( ! isset( $declared[ $name ] ) || true !== $frozen[2] ) {
				continue;
			}

			if ( true !== $declared[ $name ][2] ) {
				$tightened[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$tightened,
			"{$stem} stopped allowing NULL on: " . implode( ', ', $tightened )
				. ', which rejects rows that already hold one.' . self::REMEDY
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideTables(): array {
		$cases = array();

		foreach ( array_keys( self::SCHEMAS ) as $stem ) {
			$cases[ $stem ] = array( $stem );
		}

		return $cases;
	}

	/**
	 * Read declarations straight from the Schema's raw `$columns` array.
	 *
	 * Reflection rather than instantiation: constructing the Schema also builds
	 * its Index objects, and MCPServerMeta declares an index `length` key that
	 * BerlinDB's Index does not define — a PHP 8.2 dynamic-property deprecation
	 * that would print into output and make this test risky.
	 *
	 * @return array<string, array{0: string, 1: int, 2: bool}>
	 */
	private function declared( string $stem ): array {
		$columns = ( new ReflectionClass( self::SCHEMAS[ $stem ] ) )->getDefaultProperties()['columns'];

		$out = array();

		foreach ( (array) $columns as $column ) {
			$out[ (string) $column['name'] ] = array(
				strtoupper( (string) $column['type'] ),
				isset( $column['length'] ) ? (int) $column['length'] : 0,
				! empty( $column['allow_null'] ),
			);
		}

		return $out;
	}
}
