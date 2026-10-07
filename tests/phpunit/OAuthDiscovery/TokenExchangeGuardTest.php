<?php
/**
 * Drift lock for the token-exchange server-binding guard.
 *
 * This one assertion is source-level ON PURPOSE, and it is the only one in
 * the suite that is. `TokenController::handle_token_exchange()` is private and
 * reaches a rate limiter, PKCE verification, and four repositories before it
 * gets to the guard; standing all of that up would test the harness rather
 * than the rule. The rule itself is three boolean terms, so pinning its shape
 * is proportionate.
 *
 * The rule: an auth code whose `server_id` differs from the client's
 * registration binding is LEGITIMATE — one MCP host may hold a connector to
 * each server on the site (A16). It is corruption only when the code's
 * `server_id` disagrees with the `resource` the code itself was issued for.
 * The pre-B18 guard compared against the client instead, which rejected every
 * second-server exchange with `invalid_grant` and no log line.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use PHPUnit\Framework\TestCase;

final class TokenExchangeGuardTest extends TestCase {

	private function token_controller_source(): string {
		$src = file_get_contents( dirname( __DIR__, 3 ) . '/includes/OAuth/TokenController.php' );
		self::assertNotFalse( $src, 'TokenController.php must be readable.' );
		return (string) $src;
	}

	public function test_guard_compares_the_auth_code_against_its_own_resource(): void {
		self::assertStringContainsString(
			'AuthorizationController::server_id_from_resource( $resource ) !== $server_id',
			$this->token_controller_source(),
			'Without this term a second-server exchange is rejected as invalid_grant.'
		);
	}

	public function test_corruption_is_still_rejected(): void {
		self::assertStringContainsString(
			"self::respond_error( 'invalid_grant', 'server_id mismatch between client and auth_code', 400 );",
			$this->token_controller_source(),
			'Relaxing the guard must not delete it.'
		);
	}

	public function test_the_token_carries_the_auth_codes_resource_as_its_audience(): void {
		$src = $this->token_controller_source();

		self::assertStringContainsString( '$resource  = (string) $row->resource;', $src );
		self::assertStringContainsString(
			"'resource'        => \$resource,",
			$src,
			'TokenValidator audience-checks against this value; losing it un-scopes the token.'
		);
	}
}
