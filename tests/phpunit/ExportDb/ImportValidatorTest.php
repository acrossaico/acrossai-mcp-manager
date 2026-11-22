<?php
/**
 * What the validator refuses, and why (Feature 105).
 *
 * It runs to completion before the importer writes anything, so these are the
 * only tests in the feature that can assert "nothing happened".
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ExportDb;

use AcrossAI_MCP_Manager\Includes\Export\ImportValidator;
use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Export\ImportValidator
 */
class ImportValidatorTest extends \WP_UnitTestCase {

	/**
	 * A minimal well-formed payload.
	 *
	 * @param array<string, mixed> $overrides Envelope overrides.
	 * @return array<string, mixed>
	 */
	private function payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'format'  => ExportConstants::FORMAT,
				'version' => ExportConstants::VERSION,
				'servers' => array(
					array(
						'slug'   => 'a-server',
						'origin' => ExportConstants::ORIGIN_DATABASE,
						'blocks' => array( ExportConstants::BLOCK_STATUS ),
						'status' => array( 'enabled' => true ),
					),
				),
			),
			$overrides
		);
	}

	public function test_a_well_formed_payload_is_accepted(): void {
		$this->assertIsArray( ImportValidator::check( $this->payload() ) );
	}

	/** Not JSON at all. */
	public function test_unreadable_json_is_refused(): void {
		$result = ImportValidator::parse( '{"format": broken' );

		$this->assertWPError( $result );
		$this->assertSame( 'acrossai_mcp_import_unreadable', $result->get_error_code() );
	}

	public function test_an_empty_file_is_refused(): void {
		$this->assertSame( 'acrossai_mcp_import_empty', ImportValidator::parse( "  \n " )->get_error_code() );
	}

	/** Oversized files are refused before `json_decode` sees them. */
	public function test_an_oversized_file_is_refused(): void {
		$result = ImportValidator::parse( str_repeat( 'x', ImportValidator::MAX_BYTES + 1 ) );

		$this->assertSame( 'acrossai_mcp_import_too_large', $result->get_error_code() );
	}

	/** Deep nesting is refused rather than followed. */
	public function test_excessive_nesting_is_refused(): void {
		$deep = str_repeat( '[', ImportValidator::MAX_DEPTH + 5 ) . str_repeat( ']', ImportValidator::MAX_DEPTH + 5 );

		$this->assertWPError( ImportValidator::parse( $deep ) );
	}

	/**
	 * Another plugin's JSON is refused as foreign, not as corrupt.
	 *
	 * The format check runs before the version check so the message says the
	 * useful thing: "this is not an AcrossAI export" rather than "version 0".
	 */
	public function test_a_foreign_file_is_refused_as_foreign(): void {
		$result = ImportValidator::check( array( 'version' => 1, 'servers' => array() ) );

		$this->assertSame( 'acrossai_mcp_import_foreign', $result->get_error_code() );
	}

	/**
	 * A newer format is refused rather than best-effort parsed.
	 *
	 * A later writer may give meaning to a block this build reads as "absent,
	 * leave alone" — acting on that reading would quietly undo settings the
	 * file was carrying.
	 */
	public function test_a_newer_format_is_refused(): void {
		$result = ImportValidator::check( $this->payload( array( 'version' => ExportConstants::VERSION + 1 ) ) );

		$this->assertSame( 'acrossai_mcp_import_too_new', $result->get_error_code() );
	}

	public function test_a_payload_with_no_servers_is_refused(): void {
		$result = ImportValidator::check( $this->payload( array( 'servers' => array() ) ) );

		$this->assertSame( 'acrossai_mcp_import_no_servers', $result->get_error_code() );
	}

	/**
	 * A record with no slug is refused, not skipped.
	 *
	 * The slug is the match key AND the route, so there is no way to place it.
	 * Importing the rest would be a partial result reported as a success.
	 */
	public function test_a_server_without_a_slug_is_refused(): void {
		$result = ImportValidator::check(
			$this->payload( array( 'servers' => array( array( 'origin' => 'database' ) ) ) )
		);

		$this->assertSame( 'acrossai_mcp_import_no_slug', $result->get_error_code() );
	}

	/** An unrecognised origin is read cautiously as `plugin` — update, never create. */
	public function test_an_unknown_origin_becomes_plugin(): void {
		$checked = ImportValidator::check(
			$this->payload(
				array(
					'servers' => array(
						array(
							'slug'   => 'a-server',
							'origin' => 'something-else',
						),
					),
				)
			)
		);

		$this->assertSame( ExportConstants::ORIGIN_PLUGIN, $checked['servers'][0]['origin'] );
	}

	/**
	 * A record naming a block it does not carry cannot make the importer read
	 * a missing key.
	 */
	public function test_declared_blocks_are_intersected_with_what_is_present(): void {
		$checked = ImportValidator::check(
			$this->payload(
				array(
					'servers' => array(
						array(
							'slug'   => 'a-server',
							'origin' => 'database',
							'blocks' => array( 'status', 'tools', 'abilities' ),
							'status' => array( 'enabled' => true ),
						),
					),
				)
			)
		);

		$this->assertSame( array( ExportConstants::BLOCK_STATUS ), $checked['servers'][0]['blocks'] );
	}

	/** A hand-written file with no `blocks` key falls back to what is present. */
	public function test_a_missing_blocks_key_falls_back_to_the_keys_present(): void {
		$checked = ImportValidator::check(
			$this->payload(
				array(
					'servers' => array(
						array(
							'slug'      => 'a-server',
							'origin'    => 'database',
							'status'    => array( 'enabled' => true ),
							'abilities' => array( 'policy' => 'expose' ),
						),
					),
				)
			)
		);

		$this->assertSame(
			array( ExportConstants::BLOCK_STATUS, ExportConstants::BLOCK_ABILITIES ),
			$checked['servers'][0]['blocks']
		);
	}
}
