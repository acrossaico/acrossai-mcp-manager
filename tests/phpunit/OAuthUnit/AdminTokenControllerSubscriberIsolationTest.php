<?php
/**
 * Unit tests for subscriber-exception isolation (Feature 013 T023b / SEC-011).
 *
 * Locks in the try/catch wrap around the extended-audit do_action fire.
 * A misbehaving subscriber throwing an exception MUST NOT poison the REST
 * response — the exception is caught, logged via error_log, and the handler
 * continues to return 200 to the caller.
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
	final class AdminTokenControllerSubscriberIsolationTest extends TestCase {

		private const CTRL_PATH_FROM_TEST = '/../../../includes/OAuth/AdminTokenController.php';

		private function ctrl_source(): string {
			$src = file_get_contents( __DIR__ . self::CTRL_PATH_FROM_TEST );
			self::assertNotFalse( $src, 'AdminTokenController.php must be readable.' );
			return $src;
		}

		public function test_issued_action_wrapped_in_inner_try_catch(): void {
			// The wrap must be INSIDE the outer try/catch (so the outer
			// handler still catches non-subscriber errors) but sit around
			// the do_action call directly.
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/try\s*{\s*do_action\(\s*\n?\s*'acrossai_mcp_manager_oauth_token_issued'.*?}\s*catch\s*\(\s*\\\\Throwable/s",
				$src,
				'Inner try/catch must directly wrap the issued-action do_action() call.'
			);
		}

		public function test_catch_block_logs_exception_message_via_error_log(): void {
			// The exception must be surfaced somewhere — silent swallowing
			// is B10 territory. error_log is the established fallback log
			// path for this codebase (matches ClientRegistrationController).
			$src = $this->ctrl_source();
			self::assertMatchesRegularExpression(
				"/catch\s*\(\s*\\\\Throwable\s+\\\$e\s*\)\s*{\s*error_log\(.*n8n_token_issued subscriber threw:.*\\\$e->getMessage\(\)/s",
				$src,
				'Subscriber-exception catch block must log the message via error_log() to surface the failure.'
			);
		}

		public function test_catch_block_does_not_rethrow_or_return_error(): void {
			// The whole point is that the response still 200s. The catch
			// block must not throw and must not `return new WP_Error`.
			$src = $this->ctrl_source();

			// Extract just the inner-try/catch block.
			$start = strpos( $src, "try {\n\t\t\t\tdo_action(" );
			self::assertNotFalse( $start, 'Could not locate inner try/catch start.' );

			// Take a bounded window and look for the end of the catch block.
			$window = substr( $src, $start, 2000 );
			// The catch block should be minimal — just error_log() and no
			// `throw` / `return`. Assert absence of throw + return-WP_Error
			// inside the catch block.
			$catch_pos = strpos( $window, 'catch (' );
			self::assertNotFalse( $catch_pos, 'Catch clause must exist.' );

			$catch_end = strpos( $window, "}\n", $catch_pos );
			self::assertNotFalse( $catch_end, 'Could not locate catch closing brace.' );

			$catch_body = substr( $window, $catch_pos, $catch_end - $catch_pos );
			self::assertStringNotContainsString(
				'throw ',
				$catch_body,
				'Subscriber-exception catch MUST NOT rethrow (would poison the REST response).'
			);
			self::assertStringNotContainsString(
				'return new \WP_Error',
				$catch_body,
				'Subscriber-exception catch MUST NOT return a WP_Error (would poison the REST response).'
			);
		}

		public function test_outer_try_catch_still_wraps_full_handler(): void {
			// The outer handler's try/catch must remain — it catches genuine
			// mint failures (not subscriber misbehavior). Regression guard
			// against a refactor that accidentally deletes it while adding
			// the inner one.
			$src = $this->ctrl_source();
			self::assertStringContainsString(
				"'rest_n8n_token_mint_failed'",
				$src,
				'Outer handler try/catch must remain — returns rest_n8n_token_mint_failed for genuine mint failures.'
			);
		}
	}
}
