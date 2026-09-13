<?php
/**
 * FrontendAuth::handle_cli_auth() — consent UI render + SEC-001 anti-spoof.
 *
 * The handler sources the displayed server slug from
 * CliController::peek_pending_server($code) — the transient's authoritative
 * server_id — NOT from $_GET['server']. This file proves that contract.
 *
 * @package AcrossAI_MCP_Manager\Tests\FrontendAuth
 */

namespace AcrossAI_MCP_Manager\Tests\FrontendAuth;

use AcrossAI_MCP_Manager\Includes\REST\CliController;
use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- test methods self-document; empty catches deliberately swallow the wp_die / redirect-intercept exception so the assertion path can continue.

class HandleCliAuthTest extends WP_UnitTestCase {

	const TRANSIENT_PREFIX = 'acrossai_cli_auth_';

	public function setUp(): void {
		parent::setUp();
		update_option( 'acrossai_mcp_npm_login_enabled', 1 );
		set_query_var( FrontendAuth::QUERY_VAR, '1' );

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
	}

	public function tearDown(): void {
		$_GET = array();
		delete_transient( self::TRANSIENT_PREFIX . 'codeA' );
		delete_transient( self::TRANSIENT_PREFIX . 'codeXSS' );
		delete_transient( self::TRANSIENT_PREFIX . 'codeSpoof' );
		delete_option( 'acrossai_mcp_npm_login_enabled' );
		parent::tearDown();
	}

	private function seed_pending( string $code, string $server_id ): void {
		set_transient(
			self::TRANSIENT_PREFIX . $code,
			array(
				'status'     => 'pending',
				'server_id'  => $server_id,
				'created_at' => time(),
			),
			300
		);
	}

	private function capture_render(): string {
		$_GET['action'] = 'cli_auth';
		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
			$this->rethrow_unless_expected( $e );
}
		return (string) ob_get_clean();
	}

	public function test_renders_consent_form_with_transient_bound_slug(): void {
		$this->seed_pending( 'codeA', 'real-server' );
		$_GET['code'] = 'codeA';

		$html = $this->capture_render();

		$this->assertStringContainsString( 'Authorize CLI Access', $html );
		$this->assertStringContainsString( 'real-server', $html );
		$this->assertStringContainsString( 'cli_auth_approve', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_sec_001_anti_spoof_url_server_param_is_ignored(): void {
		// SEC-001 regression — CWE-451 / CWE-441.
		// Transient says 'real-server'; URL says 'spoofed-server'.
		// Rendered HTML MUST show 'real-server', NOT 'spoofed-server'.
		$this->seed_pending( 'codeSpoof', 'real-server' );
		$_GET['code']   = 'codeSpoof';
		$_GET['server'] = 'spoofed-server';

		$html = $this->capture_render();

		$this->assertStringContainsString( 'real-server', $html );
		$this->assertStringNotContainsString( 'spoofed-server', $html );
	}

	public function test_xss_in_server_id_is_escaped_at_render(): void {
		// Defense-in-depth: even though peek_pending_server returns the
		// server_id verbatim, render-time esc_html() neutralizes any
		// markup that slipped into the transient (e.g. via Phase 6
		// auth_start sanitization gap).
		$this->seed_pending( 'codeXSS', '<script>alert(1)</script>' );
		$_GET['code'] = 'codeXSS';

		$html = $this->capture_render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_missing_code_renders_missing_params_path(): void {
		$_GET['code'] = '';
		$html         = $this->capture_render();
		$this->assertStringContainsString( 'Missing Authentication Parameters', $html );
		$this->assertStringNotContainsString( 'Authorize CLI Access', $html );
	}

	public function test_unknown_code_renders_missing_params_path(): void {
		// peek_pending_server returns null for unknown codes.
		$_GET['code'] = 'never-seeded-code';
		$html         = $this->capture_render();
		$this->assertStringContainsString( 'Missing Authentication Parameters', $html );
		$this->assertStringNotContainsString( 'Authorize CLI Access', $html );
	}

	public function test_approve_url_uses_per_code_nonce_action(): void {
		// SEC-002 regression — CWE-352.
		// The nonce in the href MUST be one we can verify via the per-code
		// action string 'cli_auth_approve_<code>'.
		$this->seed_pending( 'codeA', 'real-server' );
		$_GET['code'] = 'codeA';

		$html = $this->capture_render();

		// Extract the _wpnonce value from the href.
		$this->assertMatchesRegularExpression( '/_wpnonce=([a-f0-9]+)/', $html, 'No _wpnonce in href' );
		preg_match( '/_wpnonce=([a-f0-9]+)/', $html, $m );
		$nonce = $m[1];

		// The nonce should verify against the per-code action.
		$this->assertNotFalse( wp_verify_nonce( $nonce, 'cli_auth_approve_codeA' ) );
		// And it MUST NOT verify against the legacy action-only string.
		$this->assertFalse( wp_verify_nonce( $nonce, 'cli_auth_approve' ) );
	}

	public function test_approve_url_does_not_include_server_param(): void {
		// 2026-06-30 amendment: ?server= is no longer in the approve URL.
		$this->seed_pending( 'codeA', 'real-server' );
		$_GET['code'] = 'codeA';

		$html = $this->capture_render();

		// Approve href should contain action, code, _wpnonce — but NOT server.
		preg_match( '/href="([^"]+cli_auth_approve[^"]+)"/', $html, $m );
		$this->assertNotEmpty( $m, 'Approve href not found' );
		$href = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$this->assertStringContainsString( 'action=cli_auth_approve', $href );
		$this->assertStringContainsString( 'code=codeA', $href );
		$this->assertStringContainsString( '_wpnonce=', $href );
		$this->assertStringNotContainsString( 'server=', $href );
	}

	public function test_response_body_contains_no_theme_markup(): void {
		// FR-011 — standalone shell, no wp_head().
		$this->seed_pending( 'codeA', 'real-server' );
		$_GET['code'] = 'codeA';

		$html = $this->capture_render();

		$this->assertStringNotContainsString( 'wp-emoji-release.min.js', $html );
		$this->assertStringNotContainsString( 'api.w.org', $html );
	}

	public function test_render_output_uses_branded_card_wrapper(): void {
		// 2026-07-15 branded-card refactor — the four render sites all go
		// through render_branded_card, which emits `.acrossai-mcp-frontend`
		// as the outer wrapper class. Guard against regressions that would
		// revert to the pre-refactor bare-<h1> markup.
		$this->seed_pending( 'codeA', 'real-server' );
		$_GET['code'] = 'codeA';

		$html = $this->capture_render();

		$this->assertStringContainsString( 'class="acrossai-mcp-frontend ', $html );
		$this->assertStringContainsString( 'acrossai-mcp-frontend--consent', $html );
		$this->assertStringContainsString( 'acrossai-mcp-frontend__card', $html );
		$this->assertStringContainsString( 'acrossai-mcp-frontend__button', $html );
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
