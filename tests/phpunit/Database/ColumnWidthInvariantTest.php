<?php
/**
 * FR-010 column-width invariant test (SEC-011-001).
 *
 * A width narrowing on auth_code_hash / access_token_hash / code_challenge silently
 * degrades cryptographic properties. This test locks the widths in place.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Schema as CliAuthLogSchema;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ColumnWidthInvariantTest extends TestCase {

	/**
	 * @dataProvider provideCryptographicColumns
	 *
	 * @param class-string $schema_class    Fully qualified Schema subclass name.
	 * @param string       $column_name     Column name to inspect.
	 * @param string       $expected_type   Expected column type (e.g. 'char').
	 * @param string       $expected_length Expected length as a string.
	 */
	public function test_column_width_is_load_bearing_invariant( string $schema_class, string $column_name, string $expected_type, string $expected_length ): void {
		$schema  = new $schema_class();
		$columns = $schema->columns;

		// BerlinDB v3 normalises a Schema's declared column arrays into Column
		// objects when the Schema is constructed, so `$col['name']` fatals with
		// "Cannot use object of type …\Column as array". Read either shape.
		$read = static function ( $col, string $key ) {
			if ( is_array( $col ) ) {
				return $col[ $key ] ?? null;
			}
			return isset( $col->{$key} ) ? $col->{$key} : null;
		};

		$match = null;
		foreach ( $columns as $col ) {
			if ( $read( $col, 'name' ) === $column_name ) {
				$match = $col;
				break;
			}
		}

		$this->assertNotNull( $match, "Column '{$column_name}' not found in {$schema_class}" );
		// BerlinDB v3's Column object upper-cases `type` ('CHAR'); the Schema
		// declares it lower-case. Compare case-insensitively — the invariant is
		// the type itself, not its casing.
		$this->assertSame( strtolower( $expected_type ), strtolower( (string) $read( $match, 'type' ) ), "Column '{$column_name}' type MUST be '{$expected_type}' (FR-010 cryptographic invariant)" );
		$this->assertSame( (string) $expected_length, (string) $read( $match, 'length' ), "Column '{$column_name}' length MUST be '{$expected_length}' (FR-010 cryptographic invariant)" );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function provideCryptographicColumns(): array {
		return array(
			'CliAuthLog auth_code_hash (SHA-256)'   => array( CliAuthLogSchema::class, 'auth_code_hash', 'char', '64' ),
			'CliAuthLog code_challenge (PKCE S256)' => array( CliAuthLogSchema::class, 'code_challenge', 'char', '43' ),
		);
	}
}
