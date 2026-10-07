<?php
/**
 * Unit tests for AccessTokenRepository::group_by_grant_for_server().
 *
 * Two claude.ai accounts connecting as the same WP user share one DCR
 * client_id (metadata-fingerprint dedup) but never a token_family_id —
 * the per-grant grouping is what lets the Connections panel show them
 * as two independently revocable rows. These tests lock the helper's
 * shape in place: family-keyed grouping, a legacy fallback for
 * pre-SEC-021-001 rows with an empty family, and the token_family_id
 * column flowing up from the SQL layer.
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
	 * @covers \AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository::group_by_grant_for_server
	 */
	final class AccessTokenRepositoryGrantGroupingTest extends TestCase {

		private const REPO_PATH_FROM_TEST  = '/../../../../includes/OAuth/Repositories/AccessTokenRepository.php';
		private const QUERY_PATH_FROM_TEST = '/../../../../includes/Database/OAuthTokens/Query.php';

		private function repo_source(): string {
			$src = file_get_contents( __DIR__ . self::REPO_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'AccessTokenRepository.php must be readable.' );
			return $src;
		}

		private function query_source(): string {
			$src = file_get_contents( __DIR__ . self::QUERY_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'Query.php must be readable.' );
			return $src;
		}

		public function test_group_by_grant_for_server_exists(): void {
			self::assertStringContainsString(
				'public static function group_by_grant_for_server( int $server_id ): array',
				$this->repo_source(),
				'The per-grant grouping helper must exist with the expected signature.'
			);
		}

		public function test_grouping_key_is_family_scoped_with_legacy_fallback(): void {
			// A 36-char family groups under 'fam|{uuid}'; legacy rows with an
			// empty/short family fall back to the old (user × connector) key so
			// they do not all collapse into one bogus group.
			$src = $this->repo_source();
			self::assertStringContainsString(
				"'fam|' . \$family",
				$src,
				'Per-grant rows must be keyed by their token_family_id.'
			);
			self::assertStringContainsString(
				"'legacy|' . \$user_id . '|' . \$slug",
				$src,
				'Legacy empty-family rows must fall back to a (user × connector) key.'
			);
		}

		public function test_group_shape_exposes_family_id_legacy_flag_and_first_created_at(): void {
			$src = $this->repo_source();
			self::assertStringContainsString(
				"'token_family_id'",
				$src,
				'Group shape must expose token_family_id (empty string for legacy buckets).'
			);
			self::assertStringContainsString(
				"'is_legacy'",
				$src,
				'Group shape must expose is_legacy so the panel can render the old client-scoped actions for legacy rows.'
			);
			self::assertStringContainsString(
				"'first_created_at'",
				$src,
				'Group shape must expose first_created_at — the stable "first connected" timestamp shown in the Connected column.'
			);
		}

		public function test_tokens_query_selects_token_family_id(): void {
			// The SQL layer must surface token_family_id or the grouping helper
			// silently degrades every row into the legacy bucket.
			self::assertStringContainsString(
				't.token_family_id',
				$this->query_source(),
				'list_active_tokens_with_client_meta_for_server must SELECT t.token_family_id.'
			);
		}

		public function test_legacy_helper_still_exists_for_the_n8n_panel(): void {
			// N8nTab::render_connections_panel still consumes the per-user
			// helper; the per-grant refactor must not remove it.
			self::assertStringContainsString(
				'public static function group_by_user_and_connector_for_server( int $server_id ): array',
				$this->repo_source(),
				'group_by_user_and_connector_for_server must survive for the n8n Connections sub-panel.'
			);
		}
	}
}
