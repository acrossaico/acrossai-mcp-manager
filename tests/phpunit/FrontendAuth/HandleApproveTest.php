<?php
/**
 * FrontendAuth::handle_approve() — state-mutating approval path + SEC-002.
 *
 * Replaces the Phase 6.0 test. Aligns with the 2026-06-30 amendments:
 * per-code nonce action 'cli_auth_approve_' . $code (SEC-002 / CWE-352
 * defense); $code read+sanitized BEFORE nonce check; broadened authz (no
 * manage_options check).
 *
 * @package AcrossAI_MCP_Manager\Tests\FrontendAuth
 */

namespace AcrossAI_MCP_Manager\Tests\FrontendAuth;

use AcrossAI_MCP_Manager\Includes\REST\CliController;
use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- test methods self-document; empty catches deliberately swallow the wp_die / redirect-intercept exception so the assertion path can continue.

class HandleApproveTest extends WP_UnitTestCase {

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
		delete_transient( self::TRANSIENT_PREFIX . 'codeB' );
		delete_option( 'acrossai_mcp_npm_login_enabled' );
		parent::tearDown();
	}

	private function seed_pending( string $code ): void {
		set_transient(
			self::TRANSIENT_PREFIX . $code,
			array(
				'status'     => 'pending',
				'server_id'  => 'real-server',
				'created_at' => time(),
			),
			300
		);
	}

	/**
	 * Run handle_approve via maybe_render_page (the public entry).
	 *
	 * Catches WPDieException (the WP_UnitTestCase wrapper for wp_die) and
	 * returns the captured status + message.
	 *
	 * @return array{output: string, exception: ?\WPDieException}
	 */
	private function run_approve(): array {
		$_GET['action'] = 'cli_auth_approve';
		$exc            = null;
		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \WPDieException $e ) {
			$exc = $e;
		} catch ( \Exception $e ) {
			// wp_safe_redirect → exit also throws.
		}
		return array(
			'output'    => (string) ob_get_clean(),
			'exception' => $exc,
		);
	}

	public function test_missing_nonce_dies_403_and_approve_not_called(): void {
		// SEC-004 — nonce check happens BEFORE state mutation.
		$this->seed_pending( 'codeA' );
		$_GET['code']     = 'codeA';
		$_GET['_wpnonce'] = ''; // missing

		$call_count = 0;
		add_action(
			'set_transient_' . self::TRANSIENT_PREFIX . 'codeA',
			static function () use ( &$call_count ) {
				$call_count++;
			}
		);

		$result = $this->run_approve();

		$this->assertNotNull( $result['exception'] );
		$this->assertStringContainsString( 'Security check failed', $result['exception']->getMessage() );

		// approve_auth_code never writes to the transient on the 403 path.
		// Re-read raw payload — still pending, never flipped to approved.
		$payload = get_transient( self::TRANSIENT_PREFIX . 'codeA' );
		$this->assertIsArray( $payload );
		$this->assertSame( 'pending', $payload['status'] );
	}

	public function test_tampered_nonce_dies_403(): void {
		$this->seed_pending( 'codeA' );
		$_GET['code']     = 'codeA';
		$_GET['_wpnonce'] = 'totally-invalid-nonce-hash';

		$result = $this->run_approve();

		$this->assertNotNull( $result['exception'] );
		$this->assertStringContainsString( 'Security check failed', $result['exception']->getMessage() );

		$payload = get_transient( self::TRANSIENT_PREFIX . 'codeA' );
		$this->assertSame( 'pending', $payload['status'] );
	}

	public function test_empty_code_dies_400_before_nonce_check(): void {
		// Per 2026-06-30 amended FR-009: empty $code rejected BEFORE nonce
		// verification (avoid verifying against 'cli_auth_approve_').
		$_GET['code']     = '';
		$_GET['_wpnonce'] = wp_create_nonce( 'cli_auth_approve_codeA' );

		$result = $this->run_approve();

		$this->assertNotNull( $result['exception'] );
		$this->assertStringContainsString( 'Missing authorization code', $result['exception']->getMessage() );
	}

	public function test_sec_002_cross_code_replay_blocked(): void {
		// SEC-002 regression — CWE-352.
		// Mint a nonce for codeA, then submit it with code=codeB.
		// Per-code action binding MUST reject.
		$this->seed_pending( 'codeA' );
		$this->seed_pending( 'codeB' );

		$_GET['code']     = 'codeB';
		$_GET['_wpnonce'] = wp_create_nonce( 'cli_auth_approve_codeA' );

		$result = $this->run_approve();

		$this->assertNotNull( $result['exception'] );
		$this->assertStringContainsString( 'Security check failed', $result['exception']->getMessage() );

		// codeB remained pending — no mutation.
		$payload = get_transient( self::TRANSIENT_PREFIX . 'codeB' );
		$this->assertSame( 'pending', $payload['status'] );
	}

	public function test_valid_per_code_nonce_approves_and_redirects(): void {
		$this->seed_pending( 'codeA' );

		$_GET['code']     = 'codeA';
		$_GET['_wpnonce'] = wp_create_nonce( 'cli_auth_approve_codeA' );

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

		$result = $this->run_approve();

		// Transient flipped to approved.
		$payload = get_transient( self::TRANSIENT_PREFIX . 'codeA' );
		$this->assertIsArray( $payload );
		$this->assertSame( 'approved', $payload['status'] );

		// Redirect target points at the success page.
		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'action=cli_auth_approved', $redirect_target );
		// Redirect URL does NOT carry the consumed code or nonce.
		$this->assertStringNotContainsString( 'code=codeA', $redirect_target );
		$this->assertStringNotContainsString( '_wpnonce=', $redirect_target );
	}

	public function test_already_approved_code_renders_no_longer_valid(): void {
		// SEC-007 operational invariant: when approve_auth_code returns false
		// (because the transient is already 'approved'), the handler MUST
		// render 400 and MUST NOT redirect.
		$this->seed_pending( 'codeA' );

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

		$_GET['code']     = 'codeA';
		$_GET['_wpnonce'] = wp_create_nonce( 'cli_auth_approve_codeA' );

		// First call — flips to approved; filter throws on the redirect path.
		$this->run_approve();
		$this->assertNotNull( $redirect_target, 'first call should have redirected to success page' );

		// Reset for second call.
		$redirect_target  = null;
		$_GET['_wpnonce'] = wp_create_nonce( 'cli_auth_approve_codeA' );

		$second = $this->run_approve();

		$this->assertNotNull( $second['exception'] );
		$this->assertStringContainsString( 'no longer valid', $second['exception']->getMessage() );
		$this->assertNull( $redirect_target, 'SEC-007: must not redirect on already-approved code' );
	}

	public function test_approved_page_renders_success_message(): void {
		// Direct test of handle_approved() via the dispatcher.
		$_GET['action'] = 'cli_auth_approved';

		$this->intercept_exit();
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
		}
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'CLI Authorization Approved', $out );
		$this->assertStringContainsString( 'return to your CLI tool', $out );
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
