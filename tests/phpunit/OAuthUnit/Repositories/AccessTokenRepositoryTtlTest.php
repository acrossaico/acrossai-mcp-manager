<?php
/**
 * Unit tests for AccessTokenRepository TTL extension (Feature 013 T008).
 *
 * Locks in the invariants of the ttl_seconds param introduced by Feature 013:
 *   - Existing callers omitting the key see identical behavior (fallback
 *     to TTL_SECONDS = 3600).
 *   - Explicit ttl_seconds ≤ MAX_ADMIN_TTL_SECONDS is used as-is.
 *   - Explicit ttl_seconds > MAX_ADMIN_TTL_SECONDS is clamped to the cap
 *     (defense-in-depth beyond the REST controller's args-validator enum).
 *   - Non-positive ttl_seconds (0, negative, non-numeric string) falls
 *     back to TTL_SECONDS.
 *
 * These tests use source-content assertions (file_get_contents +
 * assertStringContainsString) rather than functional invocation of
 * AccessTokenRepository::issue() — matching the SelfDisableProbeTest
 * pattern established elsewhere in this suite. Rationale: issue() calls
 * into SecretsVault::random_token(), SecretsVault::hash(), and
 * TokensQuery::instance()->add_item() — none of which have hand-rolled
 * stubs in this project. Locking the TTL computation via source-content
 * assertions captures the invariants without requiring a WP-boot or
 * per-test stub infrastructure.
 *
 * Behavioral coverage of the full issue() path is reserved for a future
 * integration-test scaffold (out of Feature 013 scope).
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
	 * @covers \AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository::issue
	 */
	final class AccessTokenRepositoryTtlTest extends TestCase {

		private const REPO_PATH_FROM_TEST = '/../../../../includes/OAuth/Repositories/AccessTokenRepository.php';

		private function repo_source(): string {
			$path = __DIR__ . self::REPO_PATH_FROM_TEST;
			$src  = file_get_contents( $path );
			self::assertNotFalse( $src, 'AccessTokenRepository.php must be readable at ' . $path );
			return $src;
		}

		public function test_default_ttl_constant_is_3600_seconds(): void {
			// Existing behavior: 1-hour default TTL for standard OAuth
			// access tokens. Feature 013 must not change this — every
			// non-n8n caller keeps behavior identical.
			$src = $this->repo_source();
			self::assertStringContainsString(
				'private const TTL_SECONDS = 3600;',
				$src,
				'Default TTL_SECONDS must remain 3600s — Feature 013 must not drift the pre-existing constant.'
			);
		}

		public function test_max_admin_ttl_constant_is_90_days_and_public(): void {
			// C2 (security-constraints.md): MAX_ADMIN_TTL_SECONDS
			// enforced at the repository layer as defense-in-depth
			// beyond the REST controller's args-validator enum.
			//
			// Value: 7776000 seconds = 90 days = 90 × 24 × 3600.
			// Visibility: public so the controller can reference the
			// same source of truth for its enum values.
			$src = $this->repo_source();
			self::assertStringContainsString(
				'public const MAX_ADMIN_TTL_SECONDS = 7776000;',
				$src,
				'MAX_ADMIN_TTL_SECONDS must be a public const with value 7776000 (90 days).'
			);
			self::assertSame(
				7776000,
				90 * 24 * 3600,
				'Sanity check: 90 days must equal 7776000 seconds.'
			);
		}

		public function test_issue_signature_still_accepts_a_single_array_param(): void {
			// Extension must be additive — no positional param added.
			// Feature 013 threads TTL via a new key on the existing $data
			// array so every existing caller (which omits the key) is
			// binary-compatible.
			$src = $this->repo_source();
			self::assertStringContainsString(
				'public static function issue( array $data ): array {',
				$src,
				'issue() signature must remain single-array-param.'
			);
		}

		public function test_ttl_override_reads_ttl_seconds_key_from_data(): void {
			// The new key name must be exactly `ttl_seconds` — anything
			// else drifts the T017 controller callsite and every test
			// that asserts the payload shape.
			$src = $this->repo_source();
			self::assertStringContainsString(
				"isset( \$data['ttl_seconds'] ) ? (int) \$data['ttl_seconds'] : 0",
				$src,
				'TTL override must read $data[\'ttl_seconds\'] — key name is a frozen contract per contracts/rest-token.md.'
			);
		}

		public function test_ttl_is_clamped_to_max_via_min_call(): void {
			// C2 defense-in-depth: even if a caller (or a bypassed args
			// validator) tries to mint a 100-year token, the repository
			// silently clamps to MAX_ADMIN_TTL_SECONDS. Verified by the
			// presence of the min() call.
			$src = $this->repo_source();
			self::assertMatchesRegularExpression(
				'/min\(\s*\$ttl_override\s*,\s*self::MAX_ADMIN_TTL_SECONDS\s*\)/',
				$src,
				'TTL clamping must use min( $ttl_override, self::MAX_ADMIN_TTL_SECONDS ) — defense-in-depth.'
			);
		}

		public function test_non_positive_ttl_override_falls_back_to_default(): void {
			// ttl_seconds absent, 0, or negative → keep TTL_SECONDS. This
			// preserves BC for every existing caller and rejects
			// programmer errors that would otherwise mint a zero-or-negative
			// TTL token (immediately expired or persistently invalid).
			$src = $this->repo_source();
			self::assertMatchesRegularExpression(
				'/\$ttl_override\s*>\s*0\s*\?\s*min\([^)]+\)\s*:\s*self::TTL_SECONDS/',
				$src,
				'Non-positive $ttl_override must fall back to TTL_SECONDS (existing 3600s default).'
			);
		}

		public function test_expires_at_uses_computed_ttl_not_the_raw_constant(): void {
			// Bug guard: if a future refactor accidentally hardcodes
			// self::TTL_SECONDS in the expires_at computation, the
			// ttl_seconds override becomes silently ineffective.
			$src = $this->repo_source();
			self::assertMatchesRegularExpression(
				"/\\\$expires_at\\s*=\\s*gmdate\\(\\s*'Y-m-d H:i:s',\\s*time\\(\\)\\s*\\+\\s*\\\$ttl\\s*\\);/",
				$src,
				'expires_at must be computed from the resolved $ttl variable, not the raw TTL_SECONDS constant — else the ttl_seconds override is a no-op.'
			);
		}

		public function test_ttl_docblock_documents_the_new_key(): void {
			// The class docblock is a public API surface for downstream
			// integrators. Feature 013 must document the new key so a
			// future caller doesn't discover the option only by reading
			// the source.
			$src = $this->repo_source();
			self::assertStringContainsString(
				'ttl_seconds',
				$src,
				'AccessTokenRepository::issue() docblock must mention the ttl_seconds key so integrators know the extension exists.'
			);
			self::assertStringContainsString(
				'MAX_ADMIN_TTL_SECONDS',
				$src,
				'Docblock must reference the cap constant so integrators see the enforced maximum.'
			);
		}
	}
}
