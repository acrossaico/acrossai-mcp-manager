<?php
/**
 * Route-shape guards for credential issuance (Feature 099, T013).
 *
 * Two handlers currently register `POST /generate-app-password`; only the
 * first-registered ever serves a request, which left the stricter one's
 * SEC-013 invariants as dead code. Tracked as issue #193 and deliberately NOT
 * resolved by F099 — these tests only prove the feature does not make it worse.
 *
 * The count is taken from the REST server's own route table rather than by
 * calling `permission_check()` directly. That distinction is the whole point:
 * the existing PublicApiTest calls the handler directly and therefore cannot
 * see which handler routing actually selects, which is how #193 stayed
 * invisible through review.
 *
 * @package AcrossAI_MCP_Manager\Tests\REST
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\REST;

use WP_UnitTestCase;

final class GenerateAppPasswordRouteTest extends WP_UnitTestCase {

	private const ROUTE = '/acrossai-mcp-manager/v1/generate-app-password';

	/**
	 * No `do_action( 'rest_api_init' )` here on purpose. The harness already
	 * fires it, and firing it a second time re-registers every route — which
	 * doubles the handler count and makes this test report a collision that
	 * does not exist. `rest_get_server()` is enough.
	 */

	/**
	 * FR-023 / SC-C8 — the number of ways to issue a credential stays at one.
	 *
	 * This asserts the CURRENT state, including the known collision. If the
	 * count changes, either #193 was resolved (good — update this test and say
	 * so) or a third registration was added (not good).
	 */
	public function test_post_handler_count_is_unchanged(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );

		// Count DISTINCT callbacks, not handler entries. `rest_api_init` fires
		// once per test in a suite run and the REST server is a global, so
		// entries accumulate — a full-suite run sees the same two callbacks
		// registered dozens of times. Identity is what matters here; repetition
		// is a harness artifact and asserting on it makes this test depend on
		// which other tests ran first.
		$callbacks = array();

		foreach ( $routes[ self::ROUTE ] as $handler ) {
			if ( empty( $handler['methods']['POST'] ) ) {
				continue;
			}

			$callback = $handler['callback'] ?? null;

			$callbacks[] = is_array( $callback ) && isset( $callback[0], $callback[1] )
				? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1]
				: 'closure';
		}

		$distinct = array_values( array_unique( $callbacks ) );

		sort( $distinct );

		$this->assertSame(
			array(
				'AcrossAI_MCP_Manager\\Admin\\Partials\\ApplicationPasswords::generate_app_password',
				'AcrossAI_MCP_Manager\\Includes\\REST\\ClientRendererController::handle_generate',
			),
			$distinct,
			'Expected exactly the two handlers issue #193 documents — ApplicationPasswords (which serves '
			. 'every request) and ClientRendererController (unreachable). A third means FR-023 was '
			. 'violated; only one means #193 was resolved, in which case update this test and say which.'
		);
	}

	/**
	 * Every POST handler on this route must declare an explicit
	 * permission_callback. Constitution §III forbids `__return_true` on a
	 * mutating route, and this one mints credentials.
	 */
	public function test_every_post_handler_has_a_real_permission_callback(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( $routes[ self::ROUTE ] as $handler ) {
			if ( empty( $handler['methods']['POST'] ) ) {
				continue;
			}

			$this->assertArrayHasKey( 'permission_callback', $handler );
			$this->assertNotSame(
				'__return_true',
				$handler['permission_callback'],
				'A credential-minting route must never be open.'
			);
		}
	}

	/**
	 * FR-028 / SC-C22 — "no rate limiting" is a DECISION, and a negative
	 * requirement decays silently. An administrator can already mint unlimited
	 * credentials through core, so a limiter here would refuse legitimate work
	 * without removing capability from the actor it restricts.
	 *
	 * If a future change genuinely needs a limiter, FR-028 must be revised
	 * first — and the Assumptions note says why: that decision rests on the
	 * actor already holding administrator powers.
	 */
	public function test_no_rate_limiter_is_wired_to_this_route(): void {
		$source = file_get_contents(
			\ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'admin/Partials/ApplicationPasswords.php'
		);

		$this->assertIsString( $source );
		$this->assertStringNotContainsString(
			'RateLimiter',
			$source,
			'FR-028 records a deliberate decision not to throttle this route. Revise the requirement before adding one.'
		);
	}

	/**
	 * The route is reachable through real dispatch, and refuses an
	 * unauthenticated caller. Dispatching rather than calling the handler is
	 * what makes this meaningful.
	 */
	public function test_unauthenticated_request_is_refused_through_real_dispatch(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) );

		$this->assertTrue(
			$response->is_error(),
			'An anonymous caller must not be able to mint a credential.'
		);
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
