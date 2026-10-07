<?php
/**
 * Unit tests for cross-admin token isolation via TokensQuery
 * revoke_by_client_id_and_user_id() (Feature 013 T010).
 *
 * The revoke_by_client_id_and_user_id() method already existed prior to
 * Feature 013 (introduced by mcp-manager Feature 032, T083–T087 for the
 * approval-revoke cascade). Feature 013 reuses it verbatim for the n8n
 * admin regenerate flow: when one admin regenerates their n8n bearer
 * token, ONLY that admin's prior tokens on the shared "n8n" OAuth client
 * row must be revoked; every other admin's active tokens on the same
 * client row must survive.
 *
 * This test file locks in the invariants of the existing method via
 * source-content assertions (matching SelfDisableProbeTest and
 * AccessTokenRepositoryTtlTest patterns — no WP-boot, no per-test stubs
 * of the WPDB layer). The behavioral end-to-end verification lives in
 * the T029 (AdminTokenController integration extension) and T012
 * (controller test) two-admin scenarios.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
}

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuth\Repositories {

	use PHPUnit\Framework\TestCase;

	/**
	 * @covers \AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query::revoke_by_client_id_and_user_id
	 */
	final class CrossAdminTokenIsolationTest extends TestCase {

		private const QUERY_PATH_FROM_TEST = '/../../../../includes/Database/OAuthTokens/Query.php';

		private function query_source(): string {
			$path = __DIR__ . self::QUERY_PATH_FROM_TEST;
			$src  = file_get_contents( $path );
			self::assertNotFalse( $src, 'Query.php must be readable at ' . $path );
			return $src;
		}

		public function test_revoke_by_client_id_and_user_id_signature_matches_feature_013_contract(): void {
			// Feature 013 spec Assumptions + contracts/rest-token.md handler-flow
			// step 4 depend on this exact signature. If mcp-manager ever
			// changes the arg order or types, the n8n regenerate flow
			// silently miswires.
			$src = $this->query_source();
			self::assertStringContainsString(
				'public function revoke_by_client_id_and_user_id( string $client_id, int $server_id, int $user_id ): array {',
				$src,
				'revoke_by_client_id_and_user_id signature drift — Feature 013 requires ($client_id, $server_id, $user_id) in that order.'
			);
		}

		public function test_select_where_clause_scopes_on_all_three_keys(): void {
			// C3 (security-constraints.md): user_id scoping is load-bearing
			// for cross-admin isolation. If a future refactor drops the
			// user_id predicate, Admin B's regenerate would revoke Admin A's
			// active token — silent security regression.
			$src = $this->query_source();
			self::assertMatchesRegularExpression(
				"/SELECT id FROM %i WHERE client_id = %s AND server_id = %d AND user_id = %d AND revoked = 0/",
				$src,
				'SELECT WHERE clause must scope on all three keys AND revoked = 0 — user_id is the load-bearing isolation predicate.'
			);
		}

		public function test_update_where_clause_scopes_on_all_three_keys(): void {
			// Symmetric to the SELECT — the UPDATE must apply the same
			// scoping so no accidental over-revocation happens between
			// the id-capture and the UPDATE.
			$src = $this->query_source();
			self::assertMatchesRegularExpression(
				"/UPDATE %i SET revoked = 1 WHERE client_id = %s AND server_id = %d AND user_id = %d AND revoked = 0/",
				$src,
				'UPDATE WHERE clause must match the SELECT byte-for-byte — user_id predicate is load-bearing.'
			);
		}

		public function test_early_return_on_empty_or_non_positive_inputs(): void {
			// Zero/negative user_id would be a caller bug that could
			// silently mass-revoke (WHERE user_id = 0 might match ghost
			// rows). Method must reject the inputs and return empty
			// without querying.
			$src = $this->query_source();
			self::assertStringContainsString(
				"if ( '' === \$client_id || \$server_id <= 0 || \$user_id <= 0 ) {",
				$src,
				'Method must reject empty client_id or non-positive server_id / user_id — silent mass-revoke guard.'
			);
		}

		public function test_returns_array_of_revoked_ids_as_ints(): void {
			// AdminTokenController::handle() iterates the returned array
			// to fire acrossai_mcp_manager_oauth_token_revoked per row.
			// If the return contract shifts to something else (bool, count,
			// null), that iteration silently breaks.
			$src = $this->query_source();
			self::assertStringContainsString(
				"return array_map( 'intval', \$ids );",
				$src,
				'Method must return array<int, int> of revoked token IDs so callers can fire the per-row revoked action.'
			);
		}

		public function test_broader_revoke_by_client_id_exists_and_is_distinct(): void {
			// Regression guard: revoke_by_client_id() (the broader variant
			// without user_id scope) must NOT be accidentally called from
			// Feature 013's AdminTokenController. This test locks in that
			// both methods exist as distinct signatures — a code reviewer
			// grepping for 'revoke_by_client_id(' in the controller would
			// catch the wrong one being called.
			$src = $this->query_source();
			self::assertStringContainsString(
				'public function revoke_by_client_id( string $client_id, int $server_id ): array',
				$src,
				'Broader revoke_by_client_id (2 args) must remain distinct — controller must call the 3-arg user-scoped variant, not this one.'
			);
			self::assertStringContainsString(
				'public function revoke_by_client_id_and_user_id( string $client_id, int $server_id, int $user_id ): array',
				$src,
				'Narrower user-scoped variant must exist under this exact name.'
			);
		}
	}
}
