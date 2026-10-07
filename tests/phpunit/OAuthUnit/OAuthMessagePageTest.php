<?php
/**
 * Unit tests for the shared styled OAuth message page.
 *
 * Before this surface existed, browser-facing OAuth failures rendered
 * bare `<h1>OAuth error</h1>` markup (invalid client_id / redirect_uri),
 * raw JSON (the /authorize 429), or completely blank pages (POST 403s,
 * router 404). These tests lock every surface onto the shared
 * MessagePage helper + templates/oauth/message.php so a future refactor
 * cannot silently regress a surface back to an unstyled response — while
 * pinning that status codes, Retry-After, and the no-store cache defense
 * survived the restyle.
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
	 * @covers \AcrossAI_MCP_Manager\Includes\OAuth\MessagePage::render
	 */
	final class OAuthMessagePageTest extends TestCase {

		private const CONTROLLER_PATH_FROM_TEST = '/../../../includes/OAuth/AuthorizationController.php';
		private const ROUTER_PATH_FROM_TEST     = '/../../../includes/OAuth/OAuthRouter.php';
		private const HELPER_PATH_FROM_TEST     = '/../../../includes/OAuth/MessagePage.php';
		private const TEMPLATE_PATH_FROM_TEST   = '/../../../templates/oauth/message.php';

		private function controller_source(): string {
			$src = file_get_contents( __DIR__ . self::CONTROLLER_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'AuthorizationController.php must be readable.' );
			return $src;
		}

		private function router_source(): string {
			$src = file_get_contents( __DIR__ . self::ROUTER_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'OAuthRouter.php must be readable.' );
			return $src;
		}

		private function helper_source(): string {
			$src = file_get_contents( __DIR__ . self::HELPER_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'MessagePage.php must be readable.' );
			return $src;
		}

		private function template_source(): string {
			$src = file_get_contents( __DIR__ . self::TEMPLATE_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'templates/oauth/message.php must be readable.' );
			return $src;
		}

		public function test_message_template_is_styled_noindexed_and_escaped(): void {
			$src = $this->template_source();
			self::assertStringContainsString(
				'noindex, nofollow',
				$src,
				'Error pages must never be indexed.'
			);
			self::assertStringContainsString(
				'acrossai-mcp-message',
				$src,
				'Template must use its own BEM block.'
			);
			self::assertStringContainsString(
				'#f0f0f1',
				$src,
				'Template must share the consent screen background token.'
			);
			self::assertStringContainsString(
				'esc_html( $message_heading )',
				$src,
				'Heading must be escaped.'
			);
			self::assertStringContainsString(
				'esc_html( $message_paragraph )',
				$src,
				'Every body paragraph must be escaped.'
			);
		}

		public function test_helper_preserves_status_no_store_and_template_fallback(): void {
			$src = $this->helper_source();
			self::assertStringContainsString(
				'status_header( $status )',
				$src,
				'Caller-supplied status codes must pass through unchanged.'
			);
			self::assertStringContainsString(
				'CacheHeaders::send_no_store()',
				$src,
				'Memory D7 — OAuth response paths must send no-store.'
			);
			self::assertStringContainsString(
				"templates/oauth/message.php'",
				$src,
				'Helper must load the shared template with the consent-style fallback path.'
			);
		}

		public function test_controller_no_longer_echoes_raw_oauth_error_markup(): void {
			self::assertStringNotContainsString(
				'<h1>OAuth error</h1>',
				$this->controller_source(),
				'The bare unstyled inline error page must not come back.'
			);
		}

		public function test_inline_error_and_pending_approval_route_through_message_page(): void {
			$src = $this->controller_source();
			self::assertMatchesRegularExpression(
				'/function render_inline_error\([^)]*\): void \{\s*MessagePage::render\(/s',
				$src,
				'render_inline_error must delegate straight to MessagePage.'
			);
			self::assertMatchesRegularExpression(
				'/function render_pending_approval\(.*?MessagePage::render\(/s',
				$src,
				'render_pending_approval must delegate to MessagePage.'
			);
			self::assertStringNotContainsString(
				'.wrap { max-width: 460px',
				$src,
				'The duplicated inline pending-approval CSS must stay deleted.'
			);
		}

		public function test_authorize_rate_limit_emits_styled_html_with_retry_after(): void {
			$src = $this->controller_source();
			self::assertMatchesRegularExpression(
				'/function apply_rate_limit\(\): void \{.*?MessagePage::render\(\s*429,.*?Retry-After: 60/s',
				$src,
				'The /authorize 429 must render the styled page, keep status 429, and keep Retry-After: 60.'
			);
			self::assertMatchesRegularExpression(
				'/function apply_rate_limit\(\): void \{(?:(?!slow_down).)*?\n\t\}/s',
				$src,
				'The /authorize 429 must no longer emit the raw JSON slow_down body (the /token endpoint keeps its own JSON limiter).'
			);
		}

		public function test_post_failure_paths_render_styled_403_pages(): void {
			$src = $this->controller_source();
			self::assertMatchesRegularExpression(
				'/wp_verify_nonce\(.*?\)\s*\)\s*\{\s*MessagePage::render\(\s*403,/s',
				$src,
				'POST with bad/missing nonce must render a styled 403 instead of a blank body.'
			);
			self::assertMatchesRegularExpression(
				'/if \( ! is_user_logged_in\(\) \) \{.*?MessagePage::render\(\s*403,/s',
				$src,
				'POST while logged out must render a styled 403 instead of a blank body.'
			);
		}

		public function test_router_unknown_route_renders_styled_404(): void {
			$src = $this->router_source();
			self::assertMatchesRegularExpression(
				'/default:.*?MessagePage::render\(\s*404,/s',
				$src,
				'The unknown-route case must render the styled 404.'
			);
			self::assertDoesNotMatchRegularExpression(
				'/default:\s*(?:\/\/[^\n]*\n\s*)?status_header\( 404 \);\s*exit;/s',
				$src,
				'The blank-body 404 must stay gone.'
			);
		}
	}
}
