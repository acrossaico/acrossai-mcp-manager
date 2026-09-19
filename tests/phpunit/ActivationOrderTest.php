<?php
/**
 * The order `Activator::activate()` does things in, asserted as source.
 *
 * **Why this is a source-contract test and not a behavioural one.** BerlinDB's
 * `Kern\Table` force-installs on first `instance()` whenever `is_testing()` is
 * true, which it always is under PHPUnit. Every table therefore exists before
 * any test runs, and a test that activated the plugin and then counted rows
 * would pass no matter what order the activator used. The entire class of bug
 * this file exists to catch is invisible to the harness that is supposed to
 * catch it.
 *
 * That is not hypothetical. Until 0.3.6 the activator seeded its servers ten
 * lines BEFORE creating the server-tools table, so every curated tool row the
 * seeder wrote went into a table that did not exist. Nothing threw, activation
 * reported success, `DefaultServerSeederTest` stayed green, and the AcrossAI
 * server shipped empty — the exact bug the release was meant to fix. It was
 * found by installing on a real fresh site.
 *
 * So the order itself is the contract, and reading the source is the only way
 * to hold it. The repo already uses source-contract tests for guarantees the
 * runtime cannot express (see the F080 rename gate and
 * `SettingsBulkEnableTest`'s boundary assertions).
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit;

use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ActivationOrderTest extends WP_UnitTestCase {

	/**
	 * The activator's source, once.
	 *
	 * @return string
	 */
	private function source(): string {
		$path = dirname( __DIR__, 2 ) . '/includes/Activator.php';

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * Character offset of a call in the activator body, or -1.
	 *
	 * @param  string $source The file contents.
	 * @param  string $needle The call to locate.
	 * @return int
	 */
	private function position( string $source, string $needle ): int {
		$at = strpos( $source, $needle );

		$this->assertNotFalse( $at, "Expected `{$needle}` in Activator::activate()." );

		return (int) $at;
	}

	/**
	 * Both tables the seeder writes to must be created before it runs.
	 *
	 * The seeder writes the `tool_*` columns on the servers table AND the
	 * type's curated slugs as rows in the server-tools table. Missing either
	 * one loses data silently: `$wpdb` returns false, BerlinDB's Query layer
	 * has no DDL of its own, and nothing raises.
	 */
	public function test_both_tables_the_seeder_writes_to_exist_before_it_runs(): void {
		$source = $this->source();

		$servers = $this->position( $source, 'MCPServerTable::instance()->maybe_upgrade()' );
		$tools   = $this->position( $source, 'MCPServerToolTable::instance()->maybe_upgrade()' );
		$seed    = $this->position( $source, 'DefaultServerSeeder::seed()' );

		$this->assertLessThan(
			$seed,
			$servers,
			'The servers table must be created before the seeder INSERTs into it.'
		);
		$this->assertLessThan(
			$seed,
			$tools,
			'The server-tools table must be created before the seeder writes curated rows — '
			. 'writing them into a missing table fails silently and is never retried.'
		);
	}

	/**
	 * A write that cannot fail visibly cannot be verified by anyone.
	 *
	 * `replace_set()` reported every requested slug as added regardless of
	 * whether the INSERT succeeded, which is how the above reached a release
	 * branch past green CI and a manual pass. Asserted as source for the same
	 * reason as the ordering: under PHPUnit the table always exists, so the
	 * failure path cannot be exercised.
	 */
	public function test_replace_set_reports_only_rows_it_actually_inserted(): void {
		$path = dirname( __DIR__, 2 ) . '/includes/Database/MCPServerTool/Query.php';
		$this->assertFileExists( $path );

		$source = (string) file_get_contents( $path );

		$this->assertStringContainsString(
			'\'added\'   => $inserted,',
			$source,
			'replace_set() must return the slugs it inserted, not the slugs it was asked to insert.'
		);
	}
}
