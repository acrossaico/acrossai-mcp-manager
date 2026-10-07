<?php
/**
 * F095 — regression guard for the F083 landmine removal.
 *
 * Two assertions that pull in OPPOSITE directions, which is the whole point.
 * F083 shipped a one-shot sweeper that dropped four orphaned OAuth table names
 * during normal `admin_init` operation, and listed the same four names in
 * `uninstall.php`. F095 reinstates tables under exactly those names, so:
 *
 *   - the SWEEPER had to go. It drops tables that are present AND empty, and a
 *     freshly created, still-empty table on a site that had not yet run the
 *     sweep is precisely its target. Its own guards (empty-only, run-once) do
 *     not close that race.
 *   - the UNINSTALL ENTRIES had to STAY. uninstall.php runs only at uninstall,
 *     never during normal operation, so it was never part of that hazard — and
 *     now that the plugin owns these tables, dropping them there is correct.
 *     Removing them would leak four tables, which is the B44 failure mode:
 *     adding a BerlinDB Table subclass does not automatically add its table to
 *     that array, and nothing fails to compile if it is missing.
 *
 * A future reader who deletes the sweeper's leftovers "for consistency", or who
 * reads the obsolete F083 rationale and prunes the uninstall entries as stale,
 * breaks one half or the other. This test fails loudly in both directions.
 *
 * Harness notes:
 *   - `set_up()` / `tear_down()` MUST be public — this WP test library fatals
 *     on protected ones (B56). Neither is needed here; no state is touched.
 *   - Deliberately asserts against SOURCE TEXT for the uninstall entries rather
 *     than executing `uninstall.php`, which would drop live tables.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use WP_UnitTestCase;

/**
 * Guards both halves of the F095 landmine removal.
 */
class LegacyCleanupRemovedTest extends WP_UnitTestCase {

	/**
	 * The four table names F083 swept and F095 reinstated as plugin-owned.
	 *
	 * Prefix-less stems: `uninstall.php` composes them with `$wpdb->prefix`.
	 *
	 * @var string[]
	 */
	private const REINSTATED_STEMS = array(
		'acrossai_mcp_oauth_clients',
		'acrossai_mcp_oauth_tokens',
		'acrossai_mcp_oauth_auth_codes',
		'acrossai_mcp_connector_approved_users',
	);

	/** Absolute path to the plugin root. */
	private static function plugin_root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * The sweeper must be gone — class, file, and call site.
	 */
	public function test_legacy_sweeper_class_no_longer_exists(): void {
		$this->assertFalse(
			class_exists( '\AcrossAI_MCP_Manager\Includes\Database\LegacyOAuthCleanup' ),
			'LegacyOAuthCleanup must not exist: it drops empty tables during admin_init, '
				. 'and F095 creates tables under the exact names it targets.'
		);

		$this->assertFileDoesNotExist(
			self::plugin_root() . '/includes/Database/LegacyOAuthCleanup.php',
			'The sweeper source file must be deleted, not merely unwired.'
		);
	}

	/**
	 * No caller may resurrect the sweeper.
	 */
	public function test_no_live_reference_to_the_sweeper_remains(): void {
		foreach ( array( '/includes/Main.php', '/includes/Activator.php', '/uninstall.php' ) as $relative ) {
			$source = (string) file_get_contents( self::plugin_root() . $relative );

			$this->assertStringNotContainsString(
				'LegacyOAuthCleanup',
				$source,
				$relative . ' still references the deleted sweeper.'
			);
		}
	}

	/**
	 * The uninstall drop list must STILL carry all four names (B44).
	 */
	public function test_uninstall_still_drops_the_four_reinstated_tables(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/uninstall.php' );

		foreach ( self::REINSTATED_STEMS as $stem ) {
			$this->assertStringContainsString(
				"'" . $stem . "'",
				$source,
				sprintf(
					'uninstall.php must keep dropping %s. These are now the plugin\'s own tables; '
						. 'removing the entry leaks the table on uninstall (B44). The F083 orphan '
						. 'rationale is obsolete, but the entries themselves are not.',
					$stem
				)
			);
		}
	}

	/**
	 * The rewritten comment must warn the next reader off deleting them.
	 */
	public function test_uninstall_carries_the_do_not_remove_rationale(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/uninstall.php' );

		$this->assertStringContainsString(
			'DO NOT REMOVE THESE FOUR ENTRIES',
			$source,
			'The drop-list entries need a standing explanation. Without it the next reader '
				. 'meets an obsolete F083 orphan-cleanup rationale, concludes the entries are '
				. 'stale, and deletes them.'
		);
	}
}
