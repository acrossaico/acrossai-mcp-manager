<?php
/**
 * Unit tests for the extended-audit payload invariant (Feature 013 T023).
 *
 * Locks in the extended `acrossai_mcp_manager_oauth_token_issued` fire
 * shape at the controller: 4 pre-existing positional args + new 5th
 * `array $metadata` arg with keys `[flow, server_id, ttl_seconds,
 * expires_at, regenerated]`.
 *
 * Sibling to AdminTokenControllerTest — scoped narrowly to the C4
 * defense-in-depth invariant (no token value in ANY arg or metadata
 * value, BC preservation for 4-arg subscribers).
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
	 * @covers \AcrossAI_MCP_Manager\Includes\OAuth\AdminTokenController::handle
	 */
	final class AdminTokenControllerActionPayloadTest extends TestCase {

		private const CTRL_PATH_FROM_TEST = '/../../../includes/OAuth/AdminTokenController.php';

		private function ctrl_source(): string {
			$src = file_get_contents( __DIR__ . self::CTRL_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'AdminTokenController.php must be readable.' );
			return $src;
		}

		public function test_extended_signature_has_exactly_five_args(): void {
			// Extract the do_action call block for the issued action and
			// count the top-level positional args. Grep-based extraction is
			// brittle-but-bounded: the block is small and stable.
			$src   = $this->ctrl_source();
			$start = strpos( $src, "'acrossai_mcp_manager_oauth_token_issued'" );
			self::assertNotFalse( $start, 'Issued-action fire must exist.' );

			// Find the matching close-paren for the do_action call.
			$open = strpos( $src, '(', $start - 40 ); // walk back to find the do_action(
			self::assertNotFalse( $open, 'Could not locate do_action( opening paren.' );

			// Positional args = 1 action name + 4 scalars + 1 metadata array = 5 real args
			// (the action name string is arg 0). Assert by presence of the
			// four scalars in the correct order.
			$block = substr( $src, $start, 2000 );
			self::assertMatchesRegularExpression(
				'/\'acrossai_mcp_manager_oauth_token_issued\',\s*\(int\)\s*\$token\[.id.\],\s*\(string\)\s*\$client->client_id,\s*\$user_id,\s*self::CONNECTOR_SLUG,\s*array\(/s',
				$block,
				'Extended fire must pass exactly [action_name, (int) token_id, (string) client_id, user_id, connector_slug, metadata_array] — 4 pre-existing positional args + 5th metadata array.'
			);
		}

		public function test_metadata_has_five_keys_in_the_exact_order(): void {
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/array\(\s*'flow'\s*=>\s*'n8n_admin',\s*'server_id'\s*=>\s*\\\$server_id,\s*'ttl_seconds'\s*=>\s*\\\$ttl_seconds,\s*'expires_at'\s*=>[^,]+,\s*'regenerated'\s*=>\s*\\\$regenerated,\s*\)/s",
				$src,
				'Metadata must have exactly [flow, server_id, ttl_seconds, expires_at, regenerated] in that order.'
			);
		}

		public function test_connector_slug_frozen_to_n8n(): void {
			$src = $this->ctrl_source();
			self::assertStringContainsString(
				"CONNECTOR_SLUG = 'n8n'",
				$src,
				"connector_slug must be frozen to 'n8n' per A3 (also arg 4 of the extended fire)."
			);
		}

		public function test_metadata_does_not_reference_access_token_field(): void {
			// Fuzz-check: the token value is at $token['raw'] (or referenced
			// as access_token in the response body). Neither key name may
			// appear in the metadata array construction block.
			$src = $this->ctrl_source();

			$issued_pos = strpos( $src, "'acrossai_mcp_manager_oauth_token_issued'" );
			self::assertNotFalse( $issued_pos, 'Issued fire must exist.' );

			// Take a bounded window from the fire onward and confirm neither
			// `$token['raw']` nor 'access_token' key appear until the closing
			// paren of the do_action call.
			$snippet = substr( $src, $issued_pos, 1000 );
			$end     = strpos( $snippet, ');' );
			self::assertNotFalse( $end, 'Could not locate end of do_action(...).' );

			$fire_block = substr( $snippet, 0, $end );
			self::assertStringNotContainsString(
				"'access_token'",
				$fire_block,
				"Metadata must NOT contain an 'access_token' key (C4 invariant 3)."
			);
			self::assertStringNotContainsString(
				"\$token['raw']",
				$fire_block,
				'Metadata must NOT reference $token[raw] (C4 invariant 3 — no secret substring in payload).'
			);
		}

		public function test_bc_pattern_for_four_arg_subscribers_preserved(): void {
			// BC preservation: TokenController.php:178,297 still fires with
			// only 4 positional args. AdminTokenController's 5-arg fire is
			// safe because WP's $accepted_args limits 4-arg subscribers to
			// their original 4 args. Assert the 4 pre-existing positional
			// args are the FIRST four, in the frozen order.
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/'acrossai_mcp_manager_oauth_token_issued',\s*\(int\)\s*\\\$token\['id'\],\s*\(string\)\s*\\\$client->client_id,\s*\\\$user_id,\s*self::CONNECTOR_SLUG,/s",
				$src,
				'Pre-existing 4-arg order must be (token_id, client_id, user_id, connector_slug) — 5th metadata arg trails.'
			);
		}
	}
}
