<?php
/**
 * FrontendAuth::maybe_render_page() — global guard + login redirect + kill switch.
 *
 * Replaces the Phase 6.0 test file. Aligns with the re-spec'd FR-007
 * (no manage_options check; new QUERY_VAR; SEC-005 503 hardening).
 *
 * @package AcrossAI_MCP_Manager\Tests\FrontendAuth
 */

namespace AcrossAI_MCP_Manager\Tests\FrontendAuth;

use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- test methods self-document; empty catches deliberately swallow the wp_die / redirect-intercept exception so the assertion path can continue.

class MaybeRenderPageTest extends WP_UnitTestCase {

	public function tearDown(): void {
		$_GET = array();
		set_query_var( FrontendAuth::QUERY_VAR, '' );
		delete_option( 'acrossai_mcp_npm_login_enabled' );
		parent::tearDown();
	}

	public function test_query_var_absent_short_circuits_with_no_output(): void {
		// FR-007.1 — global guard.
		set_query_var( FrontendAuth::QUERY_VAR, '' );
		$this->intercept_exit();
		ob_start();
		FrontendAuth::instance()->maybe_render_page();
		$out = (string) ob_get_clean();
		$this->assertSame( '', $out );
	}

	public function test_logged_out_redirects_to_wp_login_with_base_url_only(): void {
		// FR-007.3 + research §R3 — base URL only, no query preservation.
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		wp_set_current_user( 0 );
		$_GET = array(
			'action'   => 'cli_auth_approve',
			'code'     => 'leaky-code',
			'server'   => 'leaky-server',
			'_wpnonce' => 'leaky-nonce',
		);

		$redirect_target = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirect_target ) {
				$redirect_target = $location;
				// Throw so the test never reaches the exit; the
				// throw-from-filter pattern matches the repo's
				// existing convention (see OAuth tests).
				throw new \RuntimeException( 'redirect_intercepted' );
			},
			10,
			1
		);

		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \RuntimeException $e ) {
			// expected — redirect was intercepted before exit.
		}
		ob_end_clean();

		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'wp-login.php', $redirect_target );
		// redirect_to must carry the BASE URL only, NOT the leaky GET params.
		$this->assertStringNotContainsString( 'leaky-code', $redirect_target );
		$this->assertStringNotContainsString( 'leaky-server', $redirect_target );
		$this->assertStringNotContainsString( 'leaky-nonce', $redirect_target );
		$this->assertStringNotContainsString( 'cli_auth_approve', $redirect_target );
	}

	public function test_kill_switch_off_renders_503_disabled_notice(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		delete_option( 'acrossai_mcp_npm_login_enabled' );

		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
			$this->rethrow_unless_expected( $e );
}
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'CLI Login Not Enabled', $out );
		$this->assertStringContainsString( 'noindex,nofollow', $out, 'SEC-005: noindex meta missing on 503' );
	}

	public function test_kill_switch_off_does_not_dispatch_to_handlers(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		delete_option( 'acrossai_mcp_npm_login_enabled' );
		$_GET['action'] = 'cli_auth';
		$_GET['code']   = 'whatever';

		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
			$this->rethrow_unless_expected( $e );
}
		$out = (string) ob_get_clean();

		// The disabled-notice page, not the consent form.
		$this->assertStringContainsString( 'CLI Login Not Enabled', $out );
		$this->assertStringNotContainsString( 'Authorize CLI Access', $out );
	}

	public function test_unknown_action_falls_through_to_cli_auth_default(): void {
		// FR-008 dispatch — unknown actions render the default cli_auth path
		// which (with empty code) emits the missing-params message.
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		update_option( 'acrossai_mcp_npm_login_enabled', 1 );
		$_GET['action'] = 'totally-unknown-action';
		$_GET['code']   = '';

		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
			$this->rethrow_unless_expected( $e );
}
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'Missing Authentication Parameters', $out );
	}

	public function test_subscriber_role_can_reach_dispatch(): void {
		// FR-007.4 — NO manage_options check. Any logged-in user proceeds.
		// SC-002 regression.
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		update_option( 'acrossai_mcp_npm_login_enabled', 1 );
		$_GET['action'] = 'cli_auth';
		$_GET['code']   = '';

		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
			$this->rethrow_unless_expected( $e );
}
		$out = (string) ob_get_clean();

		// We should hit handle_cli_auth (missing-params path because code='').
		// We should NOT see a 403 / "You do not have permission" message.
		$this->assertStringContainsString( 'Missing Authentication Parameters', $out );
		$this->assertStringNotContainsString( 'do not have permission', $out );
	}

	public function test_singleton_instance_is_stable(): void {
		// A2 / S6 — singleton + private ctor.
		$a = FrontendAuth::instance();
		$b = FrontendAuth::instance();
		$this->assertSame( $a, $b );
	}

	// --------------------------------------------------------------------
	// R3 amendment (2026-07-15) — preserve ?action=cli_auth&code=<hex>
	// in the redirect target when logged out, with hex-format validation.
	// --------------------------------------------------------------------

	public function test_login_redirect_preserves_action_and_code_when_code_is_valid_hex(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		wp_set_current_user( 0 );
		$valid_hex_code = 'd83b9450d3686ff64cf546546cb82189'; // 32-char hex — CLI-generated shape.
		$_GET           = array(
			'action' => 'cli_auth',
			'code'   => $valid_hex_code,
			'server' => 'mcp-adapter-default-server', // MUST NOT be preserved per SEC-001.
		);

		$redirect_target = $this->intercept_next_redirect();

		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'wp-login.php', $redirect_target );

		// Extract and decode the redirect_to param embedded inside the login URL.
		$query = wp_parse_url( $redirect_target, PHP_URL_QUERY );
		parse_str( (string) $query, $parsed );
		$this->assertArrayHasKey( 'redirect_to', $parsed );
		$decoded_target = $parsed['redirect_to'];

		$this->assertStringContainsString( 'action=cli_auth', $decoded_target );
		$this->assertStringContainsString( 'code=' . $valid_hex_code, $decoded_target );
		// SEC-001: `server` MUST NOT be in the preserved URL.
		$this->assertStringNotContainsString( 'server=', $decoded_target );
	}

	public function test_login_redirect_falls_back_to_base_when_code_is_not_hex(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		wp_set_current_user( 0 );
		$_GET = array(
			'action' => 'cli_auth',
			'code'   => 'not-hex-at-all', // Contains hyphens; not 32 chars.
		);

		$redirect_target = $this->intercept_next_redirect();

		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'wp-login.php', $redirect_target );

		$query = wp_parse_url( $redirect_target, PHP_URL_QUERY );
		parse_str( (string) $query, $parsed );
		$this->assertArrayHasKey( 'redirect_to', $parsed );
		$decoded_target = $parsed['redirect_to'];

		// Load-bearing invariant: non-hex code MUST fall through to base-URL only.
		$this->assertStringNotContainsString( 'action=cli_auth', $decoded_target );
		$this->assertStringNotContainsString( 'code=', $decoded_target );
	}

	public function test_login_redirect_falls_back_to_base_when_action_missing(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		wp_set_current_user( 0 );
		$_GET = array(
			// No `action` param at all — must not preserve `code` alone.
			'code' => 'd83b9450d3686ff64cf546546cb82189',
		);

		$redirect_target = $this->intercept_next_redirect();

		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'wp-login.php', $redirect_target );

		$query = wp_parse_url( $redirect_target, PHP_URL_QUERY );
		parse_str( (string) $query, $parsed );
		$decoded_target = $parsed['redirect_to'] ?? '';

		$this->assertStringNotContainsString( 'action=', $decoded_target );
		$this->assertStringNotContainsString( 'code=', $decoded_target );
	}

	/**
	 * Intercept the next wp_redirect() call and return its target URL.
	 * Filter throws to abort the exit that would follow the redirect.
	 */
	private function intercept_next_redirect(): ?string {
		$redirect_target = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirect_target ) {
				$redirect_target = $location;
				throw new \RuntimeException( 'redirect_intercepted' );
			},
			10,
			1
		);

		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \RuntimeException $e ) {
			// expected — redirect was intercepted before exit.
		}
		ob_end_clean();

		return $redirect_target;
	}

	/**
	 * Swallow only the wp_die / redirect intercept these render paths raise on
	 * purpose, and re-throw anything else.
	 *
	 * The previous `catch ( \Exception $e ) {}` hid every failure mode: when
	 * this suite first ran, 13 render assertions failed with the useless
	 * "Failed asserting that '' contains ..." because whatever
	 * maybe_render_page() actually threw was discarded before a single byte
	 * was echoed.
	 *
	 * @param \Throwable $e Exception raised inside the captured render.
	 * @throws \Throwable Re-thrown unless it is an intentional wp_die intercept.
	 */
	private function rethrow_unless_expected( \Throwable $e ): void {
		if ( $e instanceof \WPDieException ) {
			return;
		}
		if ( $e instanceof \RuntimeException
			&& in_array( $e->getMessage(), array( 'exit_intercepted', 'redirect_intercepted' ), true ) ) {
			return;
		}
		throw $e;
	}


	/**
	 * Intercept FrontendAuth's terminal `exit` so assertions can run against
	 * markup the page has already echoed.
	 *
	 * `exit` is not catchable, so production routes every terminal exit on
	 * this request path through the `acrossai_mcp_frontend_auth_before_exit`
	 * action. Throwing from it is the same throw-from-hook convention these
	 * tests already use against `wp_redirect`.
	 */
	private function intercept_exit(): void {
		add_action(
			'acrossai_mcp_frontend_auth_before_exit',
			static function (): void {
				throw new \RuntimeException( 'exit_intercepted' );
			}
		);
	}

}
