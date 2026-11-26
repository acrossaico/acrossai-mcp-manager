<?php
/**
 * F106 — the absorbed Abilities Manager half's own uninstall teardown.
 *
 * F106 absorbed the separate acrossai-abilities-manager plugin's tree into
 * this one. Its two database tables and its option estate are now this
 * plugin's responsibility, and the only uninstall.php left to tear any of it
 * down is this plugin's own — the standalone plugin's uninstall.php still
 * exists on disk, but it is reachable only by uninstalling THAT plugin, not
 * this one.
 *
 * Mirrors LegacyCleanupRemovedTest's approach for the same reason: asserting
 * against SOURCE TEXT rather than executing uninstall.php. Executing it for
 * real would run `DROP TABLE` against the shared WP-PHPUnit database this
 * entire suite runs inside, destroying tables other tests in the run still
 * need.
 *
 * Covers three things a future edit could silently break:
 *   1. the absorbed half's own table/options teardown exists and sits behind
 *      its OWN gate option, independent of the MCP half's gate (see the
 *      uninstall.php docblock for why two gates, not one);
 *   2. the `abilities_access_control` table is never added to a DROP list —
 *      see the "sole ownership" note in uninstall.php: the 'abilities' slug
 *      is also used by the still-present standalone plugin, so dropping that
 *      table here could destroy a sibling installation's access rules; and
 *   3. the claim options the absorbed activator's one-time migrations stamp
 *      are reachable, so a future migration that adds one and forgets this
 *      file does not silently orphan it (the B44 failure mode already
 *      guarded for the MCP half by UninstallSweepBoundaryTest).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Uninstall
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\Uninstall;

use WP_UnitTestCase;

final class UninstallAbsorbedAbilitiesTest extends WP_UnitTestCase {

	/** Absolute path to the plugin root. */
	private static function plugin_root(): string {
		return dirname( __DIR__, 3 );
	}

	private function uninstall_source(): string {
		$src = file_get_contents( self::plugin_root() . '/uninstall.php' );
		$this->assertNotFalse( $src, 'uninstall.php must be readable.' );
		return (string) $src;
	}

	/**
	 * The absorbed half's own table must be dropped, and behind its OWN gate
	 * option — not folded into the MCP half's `acrossai_mcp_uninstall_delete_data`.
	 */
	public function test_abilities_table_is_dropped_under_its_own_gate(): void {
		$source = $this->uninstall_source();

		$this->assertStringContainsString(
			"'acrossai_abilities'",
			$source,
			'uninstall.php must drop the unified abilities table (AcrossAI_Abilities_Table).'
		);
		$this->assertStringContainsString(
			"get_option( 'acrossai_abilities_uninstall_delete_data', 0 )",
			$source,
			'The absorbed half must be gated by its OWN pre-merge opt-in option, not the MCP half\'s.'
		);
	}

	/**
	 * The two gates must be genuinely independent `if` blocks, not one
	 * nested inside (or short-circuited by) the other. A future edit that
	 * collapses them into a single OR/AND condition — or restores the old
	 * early `return` on the MCP gate — would silently change what ticking
	 * only one checkbox does.
	 */
	public function test_the_two_uninstall_gates_are_independent(): void {
		$code_only = (string) preg_replace( '#//[^\n]*#', '', $this->uninstall_source() );

		$this->assertStringNotContainsString(
			'return;',
			$code_only,
			'uninstall.php must not early-return on the MCP gate — that would skip the independent Abilities gate below it whenever the MCP box is unchecked.'
		);

		$mcp_pos       = strpos( $code_only, "get_option( 'acrossai_mcp_uninstall_delete_data', 0 )" );
		$abilities_pos = strpos( $code_only, "get_option( 'acrossai_abilities_uninstall_delete_data', 0 )" );

		$this->assertIsInt( $mcp_pos, 'The MCP gate read must exist.' );
		$this->assertIsInt( $abilities_pos, 'The Abilities gate read must exist.' );
		$this->assertLessThan(
			$abilities_pos,
			$mcp_pos,
			'The MCP gate must open its own `if` block before the Abilities gate opens its own — two sibling blocks, not one nested in the other.'
		);
	}

	/**
	 * `wp_abilities_access_control` must never be added to a DROP list. The
	 * 'abilities' TABLE_SLUG it is built from is not exclusive to code inside
	 * this plugin — the standalone acrossai-abilities-manager plugin this
	 * feature absorbed ships the identical class with the identical slug,
	 * and can be present on the same install. Dropping the table here could
	 * destroy that sibling's access-control rules.
	 */
	public function test_access_control_table_is_never_dropped(): void {
		$source = $this->uninstall_source();

		$this->assertStringNotContainsString(
			"prefix . 'abilities_access_control'",
			$source,
			'The abilities access-control table must not be added to a DROP list — ownership of the \'abilities\' TABLE_SLUG is not exclusive to this plugin (see the sole-ownership note in uninstall.php).'
		);
		$this->assertStringNotContainsString(
			"delete_option( 'wpb_ac_abilities_db_version' )",
			$source,
			'The companion schema-version option for a table this uninstall does not drop must also stay untouched.'
		);
	}

	/**
	 * Every claim option the absorbed activator's one-time migrations stamp
	 * must be reachable by this file, so a schema adoption that ran once
	 * does not leave a permanent, dangling "done" flag after a full
	 * uninstall (the B44 failure mode, applied to the absorbed half).
	 */
	public function test_absorbed_migration_claim_options_are_swept(): void {
		$source = $this->uninstall_source();

		$claim_options = array(
			'acrossai_category_slug_migration_done',
			'acrossai_library_gate_migration_done',
			'acrossai_global_styles_flag_migration_done',
			'acrossai_integration_default_opt_ins_done',
		);

		foreach ( $claim_options as $option ) {
			$this->assertStringContainsString(
				"'" . $option . "'",
				$source,
				"uninstall.php must delete the {$option} claim option stamped by the absorbed activator's migrations."
			);
		}
	}

	/**
	 * The settings options the task of absorbing this plugin explicitly
	 * calls out — the default ability capability, the File Manager settings,
	 * and the integration opt-ins — must all be reachable.
	 */
	public function test_absorbed_settings_options_are_swept(): void {
		$source = $this->uninstall_source();

		$settings_options = array(
			'acrossai_default_ability_capability',
			'acrossai_file_manager_write_allowlist',
			'acrossai_file_manager_read_allowlist',
			'acrossai_file_manager_redaction_config',
			'acrossai_file_manager_backup_enabled',
			'acrossai_file_manager_audit_log_enabled',
			'acrossai_integrations',
			'acrossai_integration_seeded_slugs',
			'acrossai_library_config',
		);

		foreach ( $settings_options as $option ) {
			$this->assertStringContainsString(
				"'" . $option . "'",
				$source,
				"uninstall.php must delete the absorbed half's {$option} option on full uninstall."
			);
		}
	}
}
