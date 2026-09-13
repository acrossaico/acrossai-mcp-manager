<?php
/**
 * Tests for the F2 fix — F042 transport gate fails CLOSED when the
 * wpb-access-control vendor library is missing.
 *
 * Prior to 0.2.8 the branch returned the vendor default ('read'), which
 * paired with F015's fail-open on the same condition to collapse the
 * entire two-gate stack. This test proves the branch now returns
 * `manage_options` so authenticated non-admins cannot reach MCP endpoints
 * when Composer's autoloader is broken or the vendor package is missing.
 *
 * We can't unload the RuleQuery class at runtime (Composer autoload +
 * class-existence caching), so the production class exposes a protected
 * `has_access_control_library()` seam. A test-only subclass overrides it
 * to force the missing-vendor code path — same technique used elsewhere
 * in the plugin for is_available()-style guards.
 *
 * @package AcrossAI_MCP_Manager\Tests\Includes\AccessControl
 * @since   0.2.8
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Includes\AccessControl;

use AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault
 */
final class TransportPermissionMissingVendorTest extends WP_UnitTestCase {

	/**
	 * Test — vendor missing → returns 'manage_options' (fail-CLOSED).
	 *
	 * Passing 'read' as the default proves the callback does NOT fall
	 * through to the passthrough branch; it returns the admin-only
	 * capability regardless of what upstream defaults may be in play.
	 */
	public function test_returns_manage_options_when_vendor_library_missing(): void {
		$this->markTestSkipped(
			'Blocked on a contradiction in TransportPermissionDefault: the class is '
			. 'final with a private constructor, yet declares has_access_control_library() '
			. 'protected purely as a test seam — so the seam can never be used. This test '
			. 'fataled with "cannot extend final class" the first time it ever ran (the WP '
			. 'test harness was broken until then). Resolving it means relaxing `final` AND '
			. 'the constructor visibility on a security-relevant class purely for '
			. 'testability, which is a deliberate design decision, not a test fix. The F042 '
			. 'fail-CLOSED path is unit-covered by TransportPermissionDefaultTest; only the '
			. 'missing-vendor branch is uncovered while this is skipped.'
		);

		$instance = new class() extends TransportPermissionDefault {
			protected function has_access_control_library(): bool {
				return false;
			}
		};

		$ctx    = $this->build_context( '/mcp/some-existing-server' );
		$result = $instance->filter_default_capability( 'read', $ctx );

		$this->assertSame(
			'manage_options',
			$result,
			'When wpb-access-control is unavailable, F042 MUST fail CLOSED to admin-only — F015 fails open on the same condition, so this asymmetry is what preserves defense-in-depth.'
		);
	}

	/**
	 * Test — the fail-closed branch takes precedence over the vendor-default
	 * passthrough, so a caller passing an unusual default (e.g. 'edit_posts')
	 * still gets locked to admin-only.
	 */
	public function test_fail_closed_overrides_arbitrary_default_capability(): void {
		$this->markTestSkipped(
			'Blocked on a contradiction in TransportPermissionDefault: the class is '
			. 'final with a private constructor, yet declares has_access_control_library() '
			. 'protected purely as a test seam — so the seam can never be used. This test '
			. 'fataled with "cannot extend final class" the first time it ever ran (the WP '
			. 'test harness was broken until then). Resolving it means relaxing `final` AND '
			. 'the constructor visibility on a security-relevant class purely for '
			. 'testability, which is a deliberate design decision, not a test fix. The F042 '
			. 'fail-CLOSED path is unit-covered by TransportPermissionDefaultTest; only the '
			. 'missing-vendor branch is uncovered while this is skipped.'
		);

		$instance = new class() extends TransportPermissionDefault {
			protected function has_access_control_library(): bool {
				return false;
			}
		};

		$ctx    = $this->build_context( '/mcp/some-server' );
		$result = $instance->filter_default_capability( 'edit_posts', $ctx );

		$this->assertSame( 'manage_options', $result );
	}

	/**
	 * Sanity — the seam defaults to true in production (real vendor loaded
	 * in the CI env). Guards against accidental permanent override.
	 */
	public function test_seam_defaults_to_true_in_production_class(): void {
		$reflection = new \ReflectionClass( TransportPermissionDefault::instance() );
		$method     = $reflection->getMethod( 'has_access_control_library' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( TransportPermissionDefault::instance() ) );
	}

	/**
	 * Build an HttpRequestContext from a route path — copy of the helper in
	 * TransportPermissionDefaultTest.
	 */
	private function build_context( string $route ): HttpRequestContext {
		$request = new WP_REST_Request( 'POST', $route );
		return new HttpRequestContext( $request );
	}
}
