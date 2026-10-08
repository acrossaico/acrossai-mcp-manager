<?php
/**
 * Locks the browser test behind the Firefox tracking-protection notice.
 *
 * Firefox's Enhanced Tracking Protection can stop an AI assistant part-way
 * through connecting: the connector is added, sign-in opens, nothing
 * completes, and neither side shows an error. Confirmed in the field on
 * salon.hvacb.com (2026-09-11) — switching protections off for the site fixed
 * it immediately.
 *
 * The notice is therefore worth showing, and worth showing ONLY to the browser
 * it applies to. Two failure directions, both bad and both cheap to test:
 * missing Firefox leaves an operator stuck on a silent failure with no hint,
 * and matching a non-Firefox browser sends someone hunting for a shield icon
 * their browser does not have.
 *
 * Adopted from acrossai-pro 0.9.16 along with the notice itself, when that
 * plugin's connector stack moved here. Converted from PHPUnit 10 attribute
 * data providers to the `@dataProvider` annotation this suite's 9.6 pin needs.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\Partials
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\Partials;

use AcrossAI_MCP_Manager\Admin\Partials\Notices;
use PHPUnit\Framework\TestCase;

final class FirefoxDetectionTest extends TestCase {

	/** @var string|null */
	private $original_ua = null;

	protected function setUp(): void {
		parent::setUp();
		$this->original_ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
	}

	protected function tearDown(): void {
		if ( null === $this->original_ua ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->original_ua;
		}
		parent::tearDown();
	}

	/**
	 * @dataProvider firefox_user_agents
	 *
	 * @param string $label      Human-readable browser name for the message.
	 * @param string $user_agent User-Agent string under test.
	 */
	public function test_firefox_and_its_forks_are_detected( string $label, string $user_agent ): void {
		self::assertTrue(
			Notices::is_firefox( $user_agent ),
			"{$label} ships Enhanced Tracking Protection and must see the notice."
		);
	}

	/**
	 * Real User-Agent strings. LibreWolf and Waterfox are Gecko forks that
	 * inherit the same protection stack and keep `Firefox/` in their UA, so
	 * matching them is correct rather than incidental. Firefox on iOS is a
	 * WebKit shell — different engine, same ETP feature.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function firefox_user_agents(): array {
		return array(
			'Firefox desktop (macOS)' => array( 'Firefox desktop', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0' ),
			'Firefox desktop (Win)'   => array( 'Firefox on Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0' ),
			'Firefox Android'         => array( 'Firefox for Android', 'Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/20100101 Firefox/132.0' ),
			'Firefox iOS'             => array( 'Firefox for iOS', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/121.0 Mobile/15E148 Safari/605.1.15' ),
			'LibreWolf'               => array( 'LibreWolf', 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0 LibreWolf/132.0' ),
			'Waterfox'                => array( 'Waterfox', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0 Waterfox/6.5.5' ),
		);
	}

	/**
	 * @dataProvider other_user_agents
	 *
	 * @param string $label      Human-readable browser name for the message.
	 * @param string $user_agent User-Agent string under test.
	 */
	public function test_other_browsers_are_not_matched( string $label, string $user_agent ): void {
		self::assertFalse(
			Notices::is_firefox( $user_agent ),
			"{$label} has no Enhanced Tracking Protection — the notice would send the reader looking for a control that is not there."
		);
	}

	/**
	 * Chrome and Edge both carry `Safari/` and `Mozilla/5.0` without being
	 * either. SeaMonkey is the trap worth pinning: it carries `Firefox/` in
	 * its UA and does not ship ETP.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function other_user_agents(): array {
		return array(
			'Chrome'    => array( 'Chrome', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' ),
			'Safari'    => array( 'Safari', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15' ),
			'Edge'      => array( 'Edge', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0' ),
			'SeaMonkey' => array( 'SeaMonkey', 'Mozilla/5.0 (X11; Linux x86_64; rv:91.0) Gecko/20100101 Firefox/91.0 SeaMonkey/2.53.18' ),
			'WP-CLI'    => array( 'A non-browser client', 'WordPress/6.9; https://example.com' ),
		);
	}

	/**
	 * The production path: no argument, read from the request. Worth covering
	 * separately because the argument form is only ever used by these tests.
	 */
	public function test_reads_the_current_request_when_no_argument_is_given(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0';
		self::assertTrue( Notices::is_firefox() );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
		self::assertFalse( Notices::is_firefox() );
	}

	/**
	 * A request with no User-Agent must not produce a notice. Advisory
	 * guidance is the right thing to drop when the input is unknown.
	 */
	public function test_absent_or_empty_user_agent_yields_no_notice(): void {
		unset( $_SERVER['HTTP_USER_AGENT'] );
		self::assertFalse( Notices::is_firefox() );

		$_SERVER['HTTP_USER_AGENT'] = '';
		self::assertFalse( Notices::is_firefox() );

		self::assertFalse( Notices::is_firefox( '' ) );
	}
}
