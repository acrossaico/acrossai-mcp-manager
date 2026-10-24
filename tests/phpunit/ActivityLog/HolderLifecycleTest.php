<?php
/**
 * A17 lifecycle contract for AuthContextHolder (Feature 096, T021).
 *
 * WP-free: the holder calls no WordPress function, so this runs against
 * tests/bootstrap.php with no WordPress and no database. That the holder is
 * testable without WordPress is itself part of the design — the three-stage
 * lifecycle is wired in Main.php per A1, never inside the class.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Observability\AuthContextHolder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Observability\AuthContextHolder
 */
final class HolderLifecycleTest extends TestCase {

	/**
	 * The holder is a singleton, so state leaks between tests unless cleared.
	 * Clearing in setUp rather than tearDown means a test that fails mid-way
	 * cannot poison the next one.
	 */
	protected function setUp(): void {
		parent::setUp();
		AuthContextHolder::instance()->clear();
	}

	public function test_starts_empty(): void {
		$holder = AuthContextHolder::instance();

		$this->assertFalse( $holder->is_oauth() );
		$this->assertSame( '', $holder->get_connector_slug() );
		$this->assertSame( '', $holder->get_client_name() );
	}

	public function test_captures_a_connector_backed_client(): void {
		$holder = AuthContextHolder::instance();
		$holder->capture_oauth_context( 'claude', 'Claude Desktop' );

		$this->assertTrue( $holder->is_oauth() );
		$this->assertSame( 'claude', $holder->get_connector_slug() );
		$this->assertSame( 'Claude Desktop', $holder->get_client_name() );
	}

	/**
	 * A bare DCR client authenticates by bearer token but has no connector
	 * profile. `is_oauth()` must stay true with an empty slug, because that
	 * distinction is what separates `oauth` from `ai-connector` downstream —
	 * collapsing them would make every bare client look like no client at all.
	 */
	public function test_bare_oauth_client_is_oauth_with_no_connector(): void {
		$holder = AuthContextHolder::instance();
		$holder->capture_oauth_context( '', '' );

		$this->assertTrue( $holder->is_oauth(), 'A bare DCR client is still OAuth.' );
		$this->assertSame( '', $holder->get_connector_slug() );
	}

	/**
	 * A17 stage 2 — `rest_post_dispatch` @ 999.
	 */
	public function test_clear_wipes_everything_and_passes_its_argument_through(): void {
		$holder = AuthContextHolder::instance();
		$holder->capture_oauth_context( 'chatgpt', 'ChatGPT' );

		$response = (object) array( 'kept' => true );
		$returned = $holder->clear( $response );

		$this->assertSame( $response, $returned, 'clear() must be usable as a filter callback.' );
		$this->assertFalse( $holder->is_oauth() );
		$this->assertSame( '', $holder->get_connector_slug() );
		$this->assertSame( '', $holder->get_client_name() );
	}

	/**
	 * A17 stage 1 — `rest_pre_dispatch` @ 5. Resets, and returns $result
	 * untouched so it cannot interfere with a short-circuiting handler.
	 */
	public function test_reset_for_request_clears_and_returns_result_unchanged(): void {
		$holder = AuthContextHolder::instance();
		$holder->capture_oauth_context( 'gemini', 'Gemini' );

		$result   = new \stdClass();
		$returned = $holder->reset_for_request( $result, null, null );

		$this->assertSame( $result, $returned );
		$this->assertFalse( $holder->is_oauth() );
	}

	/**
	 * A17 stage 3 — the `shutdown` safety net, and the whole reason this test
	 * file exists.
	 *
	 * A17 names the omission of this stage "the number-one silent bug in ad-hoc
	 * versions of this pattern". On a long-lived process a fatal kills the
	 * request before `rest_post_dispatch` fires; without a shutdown binding the
	 * captured identity survives, and the NEXT request is attributed to the
	 * PREVIOUS request's client. In an activity log — whose entire purpose is
	 * attribution — that is the worst failure mode available.
	 *
	 * Simulated here by capturing, skipping `rest_post_dispatch` entirely, and
	 * invoking only the shutdown callback.
	 */
	public function test_shutdown_clears_when_post_dispatch_never_fired(): void {
		$holder = AuthContextHolder::instance();
		$holder->capture_oauth_context( 'claude', 'Claude Desktop' );

		// No reset_for_request(), no post-dispatch — the request "died" here.
		$holder->clear();

		$this->assertFalse(
			$holder->is_oauth(),
			'Shutdown must clear, or one request\'s identity leaks into the next.'
		);
		$this->assertSame( '', $holder->get_connector_slug() );
		$this->assertSame( '', $holder->get_client_name() );
	}

	/**
	 * Non-string payloads must not reach the getters. A `do_action()` can be
	 * re-fired by any third party with anything at all, and a malformed value
	 * would otherwise poison `connection_type` for the whole request.
	 */
	public function test_non_string_payloads_are_rejected_not_coerced(): void {
		$holder = AuthContextHolder::instance();
		$holder->capture_oauth_context( array( 'nope' ), 42 );

		$this->assertTrue( $holder->is_oauth(), 'The request was still OAuth-authenticated.' );
		$this->assertSame( '', $holder->get_connector_slug() );
		$this->assertSame( '', $holder->get_client_name() );
	}

	/**
	 * The lifecycle is wired in Main.php per A1; the class must expose exactly
	 * the three callbacks that wiring needs, and no constructor.
	 */
	public function test_exposes_the_three_lifecycle_callbacks_and_a_private_constructor(): void {
		$ref = new ReflectionClass( AuthContextHolder::class );

		foreach ( array( 'capture_oauth_context', 'reset_for_request', 'clear' ) as $method ) {
			$this->assertTrue( $ref->hasMethod( $method ), "A17 wiring needs {$method}()." );
			$this->assertTrue( $ref->getMethod( $method )->isPublic(), "{$method}() must be a public hook callback." );
		}

		$this->assertTrue( $ref->isFinal() );
		$this->assertTrue( $ref->getConstructor()->isPrivate(), 'Singleton ctor must be private (A2/S6).' );
	}
}
