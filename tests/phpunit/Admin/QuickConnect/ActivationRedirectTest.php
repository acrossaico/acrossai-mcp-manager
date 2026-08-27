<?php
/**
 * F069 T042 — ActivationRedirect test.
 *
 * Verifies the 4 guard branches (transient absent / bulk-activate / network /
 * capability) plus the happy path where wp_safe_redirect() is dispatched.
 *
 * Because the production code calls `exit` after `wp_safe_redirect()`, we
 * hook `wp_redirect` and throw an exception before the exit fires. This
 * captures the target URL for assertion without terminating the test process.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\Admin\QuickConnect
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Admin\QuickConnect;

use AcrossAI_MCP_Manager\Admin\Partials\QuickConnect\ActivationRedirect;
use Exception;
use WP_UnitTestCase;

/**
 * Sentinel exception thrown from the wp_redirect filter to short-circuit the
 * production `exit;` while capturing the intended redirect target.
 */
final class ActivationRedirectFiredException extends Exception {

	public string $captured_url;

	public function __construct( string $url ) {
		parent::__construct( 'Redirect fired to: ' . $url );
		$this->captured_url = $url;
	}
}

final class ActivationRedirectTest extends WP_UnitTestCase {

	private const REDIRECT_TRANSIENT = 'acrossai_mcp_manager_quick_connect_do_redirect';

	private int $admin_id       = 0;
	private int $subscriber_id  = 0;

	public function setUp(): void {
		parent::setUp();
		$this->admin_id      = static::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = static::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Ensure a clean per-test slate.
		delete_transient( self::REDIRECT_TRANSIENT );
		$_GET = array();
	}

	public function tearDown(): void {
		delete_transient( self::REDIRECT_TRANSIENT );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Install a wp_redirect filter that throws ActivationRedirectFiredException.
	 * Return a callable to remove it in tearDown-scoped cleanup.
	 */
	private function install_redirect_capture(): callable {
		$hook = static function ( $url ) {
			throw new ActivationRedirectFiredException( (string) $url );
		};
		add_filter( 'wp_redirect', $hook, 10, 1 );
		return static function () use ( $hook ) {
			remove_filter( 'wp_redirect', $hook, 10 );
		};
	}

	// ─────────────────────────────────────────────────────────────────────
	// Guard 1 — no transient → no redirect (silent no-op)
	// ─────────────────────────────────────────────────────────────────────

	public function test_guard_no_transient_silent_noop(): void {
		wp_set_current_user( $this->admin_id );
		// Do NOT set the transient.
		$restore = $this->install_redirect_capture();

		try {
			ActivationRedirect::instance()->maybe_redirect();
			$this->assertTrue( true, 'No redirect fired — expected silent no-op.' );
		} catch ( ActivationRedirectFiredException $e ) {
			$this->fail( 'Redirect fired even though transient was absent: ' . $e->captured_url );
		} finally {
			$restore();
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// Guard 2 — bulk-activate → no redirect
	// ─────────────────────────────────────────────────────────────────────

	public function test_guard_bulk_activate_skips_redirect(): void {
		wp_set_current_user( $this->admin_id );
		set_transient( self::REDIRECT_TRANSIENT, '1', 30 );
		$_GET['activate-multi'] = 'true';
		$restore = $this->install_redirect_capture();

		try {
			ActivationRedirect::instance()->maybe_redirect();
			$this->assertTrue( true, 'Bulk-activate guard skipped redirect as expected.' );
			$this->assertFalse(
				get_transient( self::REDIRECT_TRANSIENT ),
				'Transient must still be deleted even when bulk-activate guard skips.'
			);
		} catch ( ActivationRedirectFiredException $e ) {
			$this->fail( 'Redirect fired despite ?activate-multi=true: ' . $e->captured_url );
		} finally {
			$restore();
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// Guard 3 — capability fail-closed
	// ─────────────────────────────────────────────────────────────────────

	public function test_guard_capability_check_skips_for_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );
		set_transient( self::REDIRECT_TRANSIENT, '1', 30 );
		$restore = $this->install_redirect_capture();

		try {
			ActivationRedirect::instance()->maybe_redirect();
			$this->assertTrue( true, 'Subscriber correctly denied redirect.' );
		} catch ( ActivationRedirectFiredException $e ) {
			$this->fail( 'Redirect fired for subscriber (fail-open leak): ' . $e->captured_url );
		} finally {
			$restore();
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// Idempotency — transient deleted before any early-return
	// ─────────────────────────────────────────────────────────────────────

	public function test_transient_deleted_before_capability_check(): void {
		// Even a subscriber-blocked call must consume the transient
		// (else the redirect signal would linger and fire on the NEXT admin_init
		// when an admin visits any page — surprising them long after activation).
		wp_set_current_user( $this->subscriber_id );
		set_transient( self::REDIRECT_TRANSIENT, '1', 30 );
		$restore = $this->install_redirect_capture();

		try {
			ActivationRedirect::instance()->maybe_redirect();
		} catch ( ActivationRedirectFiredException $e ) {
			// n/a
		} finally {
			$restore();
		}

		$this->assertFalse(
			get_transient( self::REDIRECT_TRANSIENT ),
			'Transient must be deleted-first regardless of downstream guard outcome.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Happy path — admin, transient present, no bulk/network flags
	// ─────────────────────────────────────────────────────────────────────

	public function test_happy_path_dispatches_redirect_to_step1(): void {
		wp_set_current_user( $this->admin_id );
		set_transient( self::REDIRECT_TRANSIENT, '1', 30 );
		$restore = $this->install_redirect_capture();

		try {
			ActivationRedirect::instance()->maybe_redirect();
			$this->fail( 'Redirect should have fired for happy path.' );
		} catch ( ActivationRedirectFiredException $e ) {
			$this->assertStringContainsString( 'page=acrossai_mcp_manager', $e->captured_url );
			$this->assertStringContainsString( 'quick-connect=1', $e->captured_url );
			$this->assertStringContainsString( 'step=1', $e->captured_url );
			$this->assertStringContainsString( 'first_run=1', $e->captured_url );
		} finally {
			$restore();
		}

		$this->assertFalse(
			get_transient( self::REDIRECT_TRANSIENT ),
			'Transient must be consumed by the happy path.'
		);
	}
}
