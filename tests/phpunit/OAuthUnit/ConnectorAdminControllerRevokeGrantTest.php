<?php
/**
 * Unit tests for the per-grant revoke surface:
 * POST /acrossai-mcp-manager/v1/oauth/revoke-grant and its server-scoped
 * TokensQuery counterpart.
 *
 * A grant (token_family_id) is minted once per consent flow, so this is
 * the only revoke granularity that can disconnect ONE of two claude.ai
 * accounts sharing a DCR client_id without touching the other. These
 * tests lock: route registration behind admin_permission, strict family
 * format validation, delegation to the server-scoped revoke, the
 * 'admin_revoke_grant' audit reason, and server_id scoping inside both
 * SQL statements of the new Query method.
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
	 * @covers \AcrossAI_MCP_Manager\Includes\OAuth\ConnectorAdminController::handle_revoke_grant
	 */
	final class ConnectorAdminControllerRevokeGrantTest extends TestCase {

		private const CONTROLLER_PATH_FROM_TEST = '/../../../includes/OAuth/ConnectorAdminController.php';
		private const QUERY_PATH_FROM_TEST      = '/../../../includes/Database/OAuthTokens/Query.php';

		private function controller_source(): string {
			$src = file_get_contents( __DIR__ . self::CONTROLLER_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'ConnectorAdminController.php must be readable.' );
			return $src;
		}

		private function query_source(): string {
			$src = file_get_contents( __DIR__ . self::QUERY_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'Query.php must be readable.' );
			return $src;
		}

		public function test_revoke_grant_route_is_registered_with_admin_permission(): void {
			$src = $this->controller_source();
			self::assertStringContainsString(
				"'/oauth/revoke-grant'",
				$src,
				'The /oauth/revoke-grant route must be registered.'
			);
			self::assertMatchesRegularExpression(
				"/'\\/oauth\\/revoke-grant',\\s*array\\(\\s*'methods'\\s*=>\\s*'POST',\\s*'callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'handle_revoke_grant'\\s*\\),\\s*'permission_callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'admin_permission'\\s*\\),/s",
				$src,
				'revoke-grant must be POST-only, dispatch to handle_revoke_grant, and be gated by the shared admin_permission callback.'
			);
		}

		public function test_handler_validates_family_format_before_revoking(): void {
			// Canonical UUID 8-4-4-4-12 shape (SEC-001 hardening) — anything
			// else must 400 before touching the DB.
			self::assertStringContainsString(
				"preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\$/', \$family_id )",
				$this->controller_source(),
				'handle_revoke_grant must validate the canonical UUID family format.'
			);
		}

		public function test_handler_uses_the_server_scoped_revoke_and_grant_reason(): void {
			$src = $this->controller_source();
			self::assertStringContainsString(
				'revoke_by_family_id_and_server_id( $family_id, $server_id )',
				$src,
				'handle_revoke_grant must call the server-scoped family revoke — never the server-agnostic revoke_by_family_id.'
			);
			self::assertStringContainsString(
				"'admin_revoke_grant'",
				$src,
				'Each revoked row must fire token_revoked with the admin_revoke_grant reason.'
			);
		}

		public function test_query_method_is_server_scoped_in_both_statements(): void {
			// Both the id-collecting SELECT and the UPDATE must carry the
			// server_id predicate, or a family leaking across servers would be
			// revocable from the wrong server's panel.
			$src = $this->query_source();
			self::assertStringContainsString(
				'public function revoke_by_family_id_and_server_id( string $family_id, int $server_id ): array',
				$src,
				'The server-scoped family revoke must exist on TokensQuery.'
			);
			self::assertStringContainsString(
				'SELECT id FROM %i WHERE token_family_id = %s AND server_id = %d AND revoked = 0',
				$src,
				'The SELECT must be scoped by server_id.'
			);
			self::assertStringContainsString(
				'UPDATE %i SET revoked = 1 WHERE token_family_id = %s AND server_id = %d AND revoked = 0',
				$src,
				'The UPDATE must be scoped by server_id.'
			);
		}

		public function test_server_agnostic_family_revoke_is_untouched_for_reuse_detection(): void {
			// TokenController's RFC 9700 refresh-reuse detection is deliberately
			// server-agnostic; the admin surface must not have repurposed it.
			self::assertStringContainsString(
				'public function revoke_by_family_id( string $family_id ): array',
				$this->query_source(),
				'revoke_by_family_id must survive unchanged for refresh-reuse detection.'
			);
		}
	}
}
