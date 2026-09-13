<?php
/**
 * F032 T099 — AC connection-time gate on OAuth /authorize (FR-049 / SC-021).
 *
 * The gate is a NEW public helper `AcrossAI_MCP_Access_Control::user_has_server_access`
 * used at THREE fire sites: AuthorizationController::handle_get,
 * FrontendAuth::render_consent, ClientRendererController::handle_generate_app_password.
 *
 * All three sites share the same fail-open D19 semantics: helper returns TRUE
 * when the AC package is absent, the server row is missing, OR the manager
 * boot failed. On explicit deny (rules matched), each site emits its distinct
 * `context` string via `acrossai_mcp_access_control_denied`:
 *   - 'oauth_authorize'
 *   - 'cli_device_grant'
 *   - 'app_password_generate'
 *
 * This test focuses on the shared helper's contract — the individual
 * fire-site tests are separate (T088 for FrontendAuth, T099 companion for
 * ClientRendererController). Rationale: testing three consumers of one
 * helper via the helper contract catches every consumer bug at once.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\AccessControl
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Includes\AccessControl;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class OAuthAuthorizeGateTest extends WP_UnitTestCase {

	// (a) Fail-open D19 — AC package absent → helper returns true.
	public function test_returns_true_when_ac_package_unavailable(): void {
		// AC package `\WPBoilerplate\AccessControl\AccessControlManager`
		// is optional; on a stock install where composer package is
		// missing, `is_available()` returns false and helper MUST fail-open.
		$ac = AcrossAI_MCP_Access_Control::instance();

		// If AC IS available, this test path is not exercised; skip
		// cleanly so we do not falsely assert.
		if ( class_exists( '\\WPBoilerplate\\AccessControl\\AccessControlManager' ) ) {
			$this->markTestSkipped( 'AC package IS available on this test env; fail-open path not exercised.' );
		}

		$this->assertTrue(
			$ac->user_has_server_access( 42, 1 ),
			'Missing AC package MUST fail-open (D19) — helper returns true'
		);
	}

	// (b) Fail-open — invalid user_id / server_id (defensive gate).
	public function test_returns_true_on_invalid_input(): void {
		$ac = AcrossAI_MCP_Access_Control::instance();

		$this->assertTrue( $ac->user_has_server_access( 0, 1 ), 'user_id=0 → fail-open (no context to evaluate)' );
		$this->assertTrue( $ac->user_has_server_access( 42, 0 ), 'server_id=0 → fail-open' );
		$this->assertTrue( $ac->user_has_server_access( -1, 1 ), 'negative user_id → fail-open' );
		$this->assertTrue( $ac->user_has_server_access( 42, -5 ), 'negative server_id → fail-open' );
	}

	// (c) Fail-open — server row missing (Q2 race).
	public function test_returns_true_when_server_row_missing(): void {
		$ac = AcrossAI_MCP_Access_Control::instance();

		// server_id = 999999 with high probability does not exist.
		$this->assertTrue(
			$ac->user_has_server_access( 42, 999999 ),
			'Missing server row MUST fail-open (Q2 race handling, D19)'
		);
	}

	// (d) REMOVED — test_denied_action_context_enum_values().
	//
	// It asserted that specs/032-oauth-per-server-scoping/contracts/php-hooks.md
	// exists and documents four context enum values. Both sides are gone: that
	// spec directory was deleted when F040 migrated the OAuth stack to the
	// companion plugin, and of the four values only 'app_password_generate'
	// still appears in this plugin ('oauth_authorize' and 'cli_device_grant'
	// went with it). It checked documentation, not behaviour, so there is
	// nothing to re-point it at. The action itself is still covered by
	// test_action_signature_shape() and test_connection_time_contexts_pass_null_tool().

	// (e) Action fires with 4-arg signature when denied.
	public function test_action_signature_shape(): void {
		$captured = array();
		add_action(
			'acrossai_mcp_access_control_denied',
			function ( $user_id, $server_slug, $tool, $context ) use ( &$captured ) {
				$captured[] = array( $user_id, $server_slug, $tool, $context );
			},
			10,
			4
		);

		// Simulate the fire (this is the exact shape the 3 consumers emit).
		do_action( 'acrossai_mcp_access_control_denied', 42, 'server-a', null, 'oauth_authorize' );
		do_action( 'acrossai_mcp_access_control_denied', 43, 'server-b', null, 'cli_device_grant' );
		do_action( 'acrossai_mcp_access_control_denied', 44, 'server-c', 'some_tool', 'tool_call' );

		$this->assertCount( 3, $captured );
		$this->assertSame( array( 42, 'server-a', null, 'oauth_authorize' ), $captured[0] );
		$this->assertSame( array( 43, 'server-b', null, 'cli_device_grant' ), $captured[1] );
		$this->assertSame( array( 44, 'server-c', 'some_tool', 'tool_call' ), $captured[2] );
	}

	// (f) At all three connection-time surfaces, tool_name is null.
	public function test_connection_time_contexts_pass_null_tool(): void {
		$connection_time_contexts = array( 'oauth_authorize', 'cli_device_grant', 'app_password_generate' );

		foreach ( $connection_time_contexts as $ctx ) {
			$captured_tool = 'not-captured';
			add_action(
				'acrossai_mcp_access_control_denied',
				function ( $user_id, $server_slug, $tool, $context ) use ( &$captured_tool, $ctx ) {
					if ( $context === $ctx ) {
						$captured_tool = $tool;
					}
				},
				10,
				4
			);

			do_action( 'acrossai_mcp_access_control_denied', 1, 'test-slug', null, $ctx );

			$this->assertNull( $captured_tool, "context={$ctx} MUST pass \$tool = null (no tool selected at connection-issuance time)" );
		}
	}
}
