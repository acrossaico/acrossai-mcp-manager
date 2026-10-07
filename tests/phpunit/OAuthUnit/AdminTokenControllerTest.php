<?php
/**
 * Unit tests for AdminTokenController (Feature 013 T012).
 *
 * Locks in REST route registration, permission-callback ordering, handler
 * flow, extended audit-action fire shape (C4), CacheHeaders wrap (C1), and
 * cross-admin isolation via the user_id-scoped revoke variant.
 *
 * Source-content assertions matching the established pattern. Full
 * behavioral end-to-end coverage requires a WP-boot integration harness
 * out of Feature 013 scope.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
}

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuth {

	use PHPUnit\Framework\TestCase;

	/**
	 * @covers \AcrossAI_MCP_Manager\Includes\OAuth\AdminTokenController
	 */
	final class AdminTokenControllerTest extends TestCase {

		private const CTRL_PATH_FROM_TEST = '/../../../includes/OAuth/AdminTokenController.php';

		private function ctrl_source(): string {
			$src = file_get_contents( __DIR__ . self::CTRL_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'AdminTokenController.php must be readable.' );
			return $src;
		}

		public function test_rest_namespace_reuses_mcp_manager_v1(): void {
			// Feature 013 constraint: no new REST namespace — reuse
			// acrossai-mcp-manager/v1 (A3 memory).
			$src = $this->ctrl_source();
			self::assertStringContainsString(
				"REST_NAMESPACE = 'acrossai-mcp-manager/v1'",
				$src,
				'REST namespace must be acrossai-mcp-manager/v1 — do not introduce a new namespace.'
			);
		}

		public function test_route_pattern_captures_server_id_only(): void {
			$src = $this->ctrl_source();
			self::assertStringContainsString(
				"/servers/(?P<server_id>\\d+)/n8n/bearer/token",
				$src,
				'Route pattern must be /servers/(server_id)/n8n/bearer/token — frozen public string per A3.'
			);
		}

		public function test_route_is_post_only(): void {
			// C6: POST-only route (prevents CSRF-via-image-tag).
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/'methods'\s*=>\s*'POST'/",
				$src,
				'Route must register methods => POST only.'
			);
		}

		public function test_ttl_seconds_enum_matches_repository_and_ui(): void {
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				'/ALLOWED_TTLS\s*=\s*array\(\s*86400,\s*604800,\s*2592000,\s*7776000\s*\)/',
				$src,
				'ALLOWED_TTLS enum must be [86400, 604800, 2592000, 7776000] — frozen shared with UI + repository.'
			);
			self::assertStringContainsString(
				'DEFAULT_TTL    = 2592000',
				$src,
				'Default TTL must be 2592000 (30 days).'
			);
		}

		public function test_permission_callback_ordering_is_fail_fast(): void {
			// Order: nonce → capability → enablement. Cheapest gates first.
			//
			// F095 dropped the fourth gate, can_use_premium_code(). That was a
			// commercial check, not a security one — the three that remain are
			// what actually guard the route, and they are unchanged.
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				'/wp_verify_nonce.*current_user_can\(\s*.manage_options.\s*\).*N8nTab::is_enabled\(\)/s',
				$src,
				'Permission callback must run gates in order: nonce → manage_options → is_enabled.'
			);
			self::assertStringNotContainsString(
				'can_use_premium_code',
				$src,
				'The licence gate must stay removed — connectors is a free capability now (FR-021).'
			);
		}

		public function test_permission_callback_returns_distinct_error_codes(): void {
			$src = $this->ctrl_source();
			self::assertStringContainsString( "'rest_cookie_invalid_nonce'", $src, 'Nonce failure must return rest_cookie_invalid_nonce (401).' );
			self::assertStringContainsString( "'rest_forbidden'", $src, 'Capability failure must return rest_forbidden (403).' );
			self::assertStringContainsString( "'rest_n8n_disabled'", $src, 'Enablement failure must return rest_n8n_disabled (403).' );
			// rest_n8n_premium_required is gone with the licence gate (FR-021).
			self::assertStringNotContainsString( "'rest_n8n_premium_required'", $src, 'The premium error code must not return.' );
		}

		public function test_handler_returns_404_for_missing_server(): void {
			$src = $this->ctrl_source();
			self::assertStringContainsString( "'rest_server_not_found'", $src, '404 code must be rest_server_not_found.' );
			self::assertStringContainsString( 'MCPServerQuery::instance()->get_item(', $src, 'Server resolution must go through MCPServerQuery.' );
		}

		public function test_client_creation_uses_empty_metadata_fingerprint(): void {
			// A4 memory: empty metadata_fingerprint classifies the durable
			// n8n client as admin-issued (not CIMD/verified).
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/'metadata_fingerprint'\s*=>\s*''/",
				$src,
				'Durable n8n admin client must be created with EMPTY metadata_fingerprint (A4 classification).'
			);
		}

		public function test_client_creation_uses_empty_redirect_uris(): void {
			// spec Assumptions: n8n admin client has redirect_uris = []
			// so DCR HTTPS gate is a no-op (auto-memory acrossai-mcp-manager-oauth-https-gate).
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/'redirect_uris'\s*=>\s*array\(\)/",
				$src,
				'Durable n8n admin client must have redirect_uris = [] — no browser round-trip; DCR HTTPS validation is a no-op.'
			);
		}

		public function test_revoke_uses_user_scoped_variant_not_broader(): void {
			// C3: cross-admin isolation. Controller MUST call the
			// user_id-scoped variant, not the broader revoke_by_client_id.
			$src = $this->ctrl_source();
			self::assertStringContainsString(
				'revoke_by_client_id_and_user_id',
				$src,
				'Controller must call revoke_by_client_id_and_user_id (user-scoped) for cross-admin isolation.'
			);
			self::assertStringNotContainsString(
				'revoke_by_client_id(',
				$src,
				'Controller must NOT call the broader revoke_by_client_id (would revoke other admins tokens).'
			);
		}

		public function test_revoke_action_fires_with_new_reason_enum(): void {
			$src = $this->ctrl_source();
			self::assertStringContainsString(
				"acrossai_mcp_manager_oauth_token_revoked",
				$src,
				'Revoked action must fire per revoked row.'
			);
			self::assertStringContainsString(
				"'n8n_admin_regenerated'",
				$src,
				'Revoked action reason must be n8n_admin_regenerated (new enum value; existing signature preserved).'
			);
		}

		public function test_issued_action_fires_with_extended_five_arg_signature(): void {
			// C4 (revised): fire the existing action with 5 positional args.
			// Existing 4-arg subscribers unaffected; new 5-arg subscribers
			// receive the metadata array. See contracts/hooks.md.
			$src = $this->ctrl_source();
			self::assertStringContainsString(
				"'acrossai_mcp_manager_oauth_token_issued'",
				$src,
				'Extended audit must fire the existing action (do not create a new one).'
			);
			// The metadata array must include flow=n8n_admin and the 4 context keys.
			self::assertStringContainsString( "'flow'        => 'n8n_admin'", $src, 'Metadata must carry flow => n8n_admin.' );
			self::assertStringContainsString( "'server_id'   => \$server_id", $src, 'Metadata must carry server_id.' );
			self::assertStringContainsString( "'ttl_seconds' => \$ttl_seconds", $src, 'Metadata must carry ttl_seconds.' );
			self::assertStringContainsString( "'expires_at'  =>", $src, 'Metadata must carry expires_at.' );
			self::assertStringContainsString( "'regenerated' => \$regenerated", $src, 'Metadata must carry regenerated.' );
		}

		public function test_issued_action_wrapped_in_try_catch_for_subscriber_isolation(): void {
			// C4 invariant 6: subscriber exceptions MUST NOT poison the REST
			// response. Wrap in try/catch that logs and continues.
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/try\s*{[^}]*do_action\(\s*\n?\s*'acrossai_mcp_manager_oauth_token_issued'.*?}\s*catch\s*\(\s*\\\\Throwable\s+\\\$e/s",
				$src,
				'Issued-action do_action() must be wrapped in try/catch for subscriber-isolation (C4 invariant 6).'
			);
		}

		public function test_cache_headers_apply_before_response(): void {
			// C1 / D7: CacheHeaders MUST wrap the token-emitting response.
			// nocache_headers() alone is insufficient (LSC/WP Rocket/W3TC/WPSC
			// don't honor arbitrary Cache-Control).
			$src = $this->ctrl_source();
			self::assertStringContainsString(
				'CacheHeaders::apply_to_rest_response',
				$src,
				'CacheHeaders::apply_to_rest_response must wrap the WP_REST_Response emitting the access token.'
			);
		}

		public function test_response_body_does_not_leak_token_via_data_attrs_or_extra_keys(): void {
			// C5 discipline at the REST layer: response body contains only
			// access_token / expires_at / resource / regenerated.
			$src = $this->ctrl_source();
			self::assertStringContainsString( "'access_token' =>", $src, 'Response must include access_token.' );
			self::assertStringContainsString( "'expires_at'   =>", $src, 'Response must include expires_at.' );
			self::assertStringContainsString( "'resource'     =>", $src, 'Response must include resource.' );
			self::assertStringContainsString( "'regenerated'  =>", $src, 'Response must include regenerated.' );
		}

		public function test_us3_revoke_call_passes_current_user_id_not_a_broader_scope(): void {
			// US3 (T029) — cross-admin isolation. Admin B's regenerate must
			// pass user_id = get_current_user_id() (Admin B) to the revoke
			// variant, NOT a broader scope (e.g. omit user_id, or use
			// $client->user_id). Any drift here silently kills Admin A's
			// active token.
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				'/\$user_id\s*=\s*\(int\)\s*get_current_user_id\(\);.*revoke_by_client_id_and_user_id\(\s*\(string\)\s*\$client->client_id,\s*\$server_id,\s*\$user_id\s*\)/s',
				$src,
				'Revoke call must pass ($client_id, $server_id, $user_id = get_current_user_id()) — cross-admin isolation depends on the user_id being the CURRENT admin.'
			);
		}

		public function test_us3_token_issue_binds_user_id_to_current_admin(): void {
			// US3 companion — token minted for Admin B must be user_id-bound
			// to Admin B, so a subsequent Admin B regenerate finds the
			// correct row. If the user_id at issue diverges from the user_id
			// at revoke, the isolation guarantee silently breaks.
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/AccessTokenRepository::issue\(.*?'user_id'\s*=>\s*\\\$user_id/s",
				$src,
				'Access token must be bound to $user_id (= get_current_user_id()) — same variable used for revoke, so the round-trip stays consistent.'
			);
		}

		public function test_throwable_handler_does_not_leak_message_into_wire_body(): void {
			// Mirror of SEC-021-T06 in ClientRegistrationController. Uses
			// non-greedy `.*?` (with /s) rather than `[^}]*` because there
			// are two Throwable catches in handle() — the inner subscriber-
			// isolation catch and the outer mint-failure catch — and the
			// `rest_n8n_token_mint_failed` code lives in the OUTER one.
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/'rest_n8n_token_mint_failed'.*?'Could not mint the n8n admin token\\.'.*?'status'\\s*=>\\s*500/s",
				$src,
				'Top-level Throwable handler must return generic rest_n8n_token_mint_failed (500) — do not leak $e->getMessage() into the wire body.'
			);
			// Also assert that $e->getMessage() does NOT appear from the
			// outer catch's `catch (` onward — defensive against a refactor
			// that starts leaking the exception message into the response.
			$outer_return_pos = strrpos( $src, "'rest_n8n_token_mint_failed'" );
			self::assertNotFalse( $outer_return_pos, 'Outer catch code must exist.' );

			// Walk back from the outer return to find the nearest `catch (`
			// keyword — that's the start of the outer catch block.
			$outer_catch_pos = strrpos( substr( $src, 0, $outer_return_pos ), 'catch (' );
			self::assertNotFalse( $outer_catch_pos, 'Outer catch keyword must precede the return.' );

			$outer_body = substr( $src, $outer_catch_pos, ( $outer_return_pos - $outer_catch_pos ) + 300 );
			self::assertStringNotContainsString(
				'$e->getMessage()',
				$outer_body,
				'Outer Throwable catch must NOT interpolate $e->getMessage() into the wire response.'
			);
		}
	}
}
