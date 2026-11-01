<?php
/**
 * Severity must follow whether there is anything to clean (issue #56).
 *
 * DISABLE_WP_CRON is the default in Local by Flywheel, WP Studio and most CI
 * images. Warning every one of those developers that a no-op will not run
 * automatically is how a diagnostic screen teaches people to ignore it, so a
 * site with no OAuth data gets `info`, and only a site with real OAuth state
 * gets `warning`.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Troubleshoot;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\WpCronCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;
use WP_UnitTestCase;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\WpCronCheck
 */
final class WpCronCheckTest extends WP_UnitTestCase {

	private const EVENT = 'acrossai_mcp_manager_oauth_cleanup';

	public function tear_down(): void {
		wp_clear_scheduled_hook( self::EVENT );
		remove_all_filters( 'acrossai_mcp_cron_is_available' );
		parent::tear_down();
	}

	/**
	 * @return array<string, string>
	 */
	private function run_check(): array {
		return ( new WpCronCheck() )->run( new Context() )->to_array();
	}

	/**
	 * The WordPress test harness defines DISABLE_WP_CRON itself, so a test
	 * cannot reach the healthy branch by unsetting a constant — and without
	 * this seam that branch would be uncovered in CI as well as locally.
	 *
	 * The filter is not a test hook bolted on for the purpose: a site that
	 * sets DISABLE_WP_CRON precisely because it drives wp-cron.php from a
	 * system cron entry is healthy, and it is the only way it can say so.
	 *
	 * @param bool $available Whether scheduled events can fire.
	 */
	private function given_cron_available( bool $available ): void {
		add_filter(
			'acrossai_mcp_cron_is_available',
			static function () use ( $available ): bool {
				return $available;
			}
		);
	}

	public function test_scheduled_and_available_passes(): void {
		$this->given_cron_available( true );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );

		$result = $this->run_check();

		self::assertSame( Result::OK, $result['status'] );
		self::assertStringContainsString( 'scheduled', $result['message'] );
	}

	/**
	 * The case issue #56 is really about: DISABLE_WP_CRON set because a real
	 * cron entry runs the event. Saying nothing is correct here.
	 */
	public function test_a_site_that_declares_real_cron_is_not_warned(): void {
		$this->given_cron_available( true );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );

		self::assertSame( Result::OK, $this->run_check()['status'] );
	}

	public function test_cron_unavailable_names_the_constant_as_the_cause(): void {
		$this->given_cron_available( false );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );

		self::assertStringContainsString( 'WP-Cron is switched off', $this->run_check()['message'] );
	}

	public function test_an_unscheduled_event_names_that_as_the_cause(): void {
		$this->given_cron_available( true );
		wp_clear_scheduled_hook( self::EVENT );

		self::assertStringContainsString( 'not scheduled', $this->run_check()['message'] );
	}

	public function test_nothing_to_clean_is_information_not_a_warning(): void {
		wp_clear_scheduled_hook( self::EVENT );

		$result = $this->run_check();

		self::assertSame( Result::INFO, $result['status'] );
		self::assertNotSame( Result::WARNING, $result['status'] );
		self::assertNotSame( Result::FAIL, $result['status'] );
	}

	/**
	 * And it says why it is not worrying, rather than leaving the operator to
	 * wonder whether the neutral colour means it did not run.
	 */
	public function test_the_quiet_case_explains_itself(): void {
		wp_clear_scheduled_hook( self::EVENT );

		$result = $this->run_check();

		self::assertStringContainsString( 'no OAuth clients', $result['message'] );
		self::assertStringContainsString( 'no work to do', $result['message'] );
	}

	public function test_the_check_writes_nothing(): void {
		wp_clear_scheduled_hook( self::EVENT );

		$before = wp_next_scheduled( self::EVENT );
		$this->run_check();

		self::assertSame( $before, wp_next_scheduled( self::EVENT ) );
	}

	public function test_it_is_registered_in_the_runner(): void {
		self::assertContains(
			WpCronCheck::class,
			\AcrossAI_MCP_Manager\Includes\Troubleshoot\CheckRunner::check_classes()
		);
	}
}
