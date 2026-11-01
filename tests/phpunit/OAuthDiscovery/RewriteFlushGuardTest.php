<?php
/**
 * Behavioural tests for `OAuthRouter::maybe_flush_rewrites()`.
 *
 * The guard used to be keyed on the plugin version alone. That answers one
 * question — "did WE change the rules" — and the symptom operators actually
 * reported was the other case: the rules went missing while the version stood
 * still, so the guard short-circuited forever and the only fix was re-saving
 * Settings → Permalinks by hand. The rules go missing whenever something
 * rewrites the option without ours registered: another plugin flushing from a
 * context where our `init` hook has not run, `wp rewrite flush --skip-plugins`,
 * a database copied from an install where this plugin was inactive, a restore
 * from before it was installed.
 *
 * `test_flushes_when_a_rule_is_missing_even_though_the_version_matches` is the
 * one that would have failed before the fix. The rest fence it in — in
 * particular that a plain-permalink site must NOT flush, because the rules can
 * never be present there and a presence check alone would flush on every
 * single admin request, forever.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use AcrossAI_MCP_Manager\Includes\OAuth\OAuthRouter;
use PHPUnit\Framework\TestCase;

final class RewriteFlushGuardTest extends TestCase {

	use ServerFixtureTrait;

	/** Flush marker option, mirrored from the private const under test. */
	private const FLUSH_OPTION = 'acrossai_mcp_oauth_rewrite_version';

	protected function setUp(): void {
		parent::setUp();
		$this->reset_fixtures();
	}

	/**
	 * Every pattern the router registers, read from the router itself rather
	 * than hard-coded, so these tests cannot pass against a stale copy of the
	 * list.
	 *
	 * @return array<int, string>
	 */
	private function registered_patterns(): array {
		$GLOBALS['acrossai_test_rewrite_rules'] = array();
		OAuthRouter::instance()->register_rewrite_rules();

		return array_map(
			static fn( array $rule ): string => (string) $rule['regex'],
			$GLOBALS['acrossai_test_rewrite_rules']
		);
	}

	/**
	 * Put a complete, healthy rewrite table in place: pretty permalinks, every
	 * one of our rules stored, and the flush marker already at this version.
	 */
	private function given_healthy_site(): void {
		$stored = array( '^wp-json/?$' => 'index.php?rest_route=/' );
		foreach ( $this->registered_patterns() as $pattern ) {
			$stored[ $pattern ] = 'index.php?stored';
		}

		$GLOBALS['acrossai_test_options'] = array(
			'permalink_structure' => '/%postname%/',
			'rewrite_rules'       => $stored,
			self::FLUSH_OPTION    => ACROSSAI_MCP_MANAGER_VERSION,
		);
		$GLOBALS['acrossai_test_flush_count'] = 0;
	}

	private function flush_count(): int {
		return (int) $GLOBALS['acrossai_test_flush_count'];
	}

	// ---- the fix ---------------------------------------------------------

	/**
	 * The regression this whole file exists for. Before the fix this flushed
	 * zero times and the operator had to re-save Permalinks.
	 */
	public function test_flushes_when_a_rule_is_missing_even_though_the_version_matches(): void {
		$this->given_healthy_site();

		$stored = $GLOBALS['acrossai_test_options']['rewrite_rules'];
		unset( $stored['^token/?$'] );
		$GLOBALS['acrossai_test_options']['rewrite_rules'] = $stored;

		OAuthRouter::maybe_flush_rewrites();

		self::assertSame( 1, $this->flush_count(), 'A missing rule must trigger a flush regardless of version.' );
	}

	public function test_missing_rules_names_exactly_what_is_absent(): void {
		$this->given_healthy_site();

		$stored = $GLOBALS['acrossai_test_options']['rewrite_rules'];
		unset( $stored['^token/?$'], $stored['^authorize/?$'] );
		$GLOBALS['acrossai_test_options']['rewrite_rules'] = $stored;

		$missing = OAuthRouter::missing_rules();

		sort( $missing );
		self::assertSame( array( '^authorize/?$', '^token/?$' ), $missing );
	}

	/**
	 * A fresh site whose rewrite table has not been built yet, but which does
	 * use pretty permalinks: everything is missing and a flush is the fix.
	 */
	public function test_absent_rewrite_table_reports_every_rule_missing(): void {
		$GLOBALS['acrossai_test_options'] = array(
			'permalink_structure' => '/%postname%/',
			self::FLUSH_OPTION    => ACROSSAI_MCP_MANAGER_VERSION,
		);
		$GLOBALS['acrossai_test_flush_count'] = 0;

		self::assertSame( $this->registered_patterns(), OAuthRouter::missing_rules() );

		OAuthRouter::maybe_flush_rewrites();
		self::assertSame( 1, $this->flush_count() );
	}

	// ---- what must NOT change -------------------------------------------

	public function test_healthy_site_at_the_current_version_does_not_flush(): void {
		$this->given_healthy_site();

		OAuthRouter::maybe_flush_rewrites();

		self::assertSame( 0, $this->flush_count(), 'Flushing on every admin request is the bug this guard prevents.' );
	}

	public function test_version_change_still_flushes_and_records_the_new_version(): void {
		$this->given_healthy_site();
		$GLOBALS['acrossai_test_options'][ self::FLUSH_OPTION ] = '0.0.1-old';

		OAuthRouter::maybe_flush_rewrites();

		self::assertSame( 1, $this->flush_count() );
		self::assertSame(
			ACROSSAI_MCP_MANAGER_VERSION,
			$GLOBALS['acrossai_test_options'][ self::FLUSH_OPTION ],
			'The marker must advance, or the next request flushes again.'
		);
	}

	/**
	 * With plain permalinks WordPress keeps no rewrite table at all, so our
	 * rules can never be present. A presence check that did not special-case
	 * this would flush on every admin request for the life of the site.
	 */
	public function test_plain_permalinks_never_flush(): void {
		$GLOBALS['acrossai_test_options'] = array(
			'permalink_structure' => '',
			'rewrite_rules'       => array(),
			self::FLUSH_OPTION    => '0.0.1-old',
		);
		$GLOBALS['acrossai_test_flush_count'] = 0;

		OAuthRouter::maybe_flush_rewrites();

		self::assertSame( 0, $this->flush_count(), 'Flushing cannot build a table the site does not use.' );
	}

	/**
	 * Plain permalinks are a different diagnosis from "your rules went
	 * missing" — the fix is choosing a permalink structure, not flushing — so
	 * the two must not report as the same failure.
	 */
	public function test_plain_permalinks_report_no_missing_rules_and_no_possibility(): void {
		$GLOBALS['acrossai_test_options'] = array( 'permalink_structure' => '' );

		self::assertFalse( OAuthRouter::rewrites_are_possible() );
		self::assertSame( array(), OAuthRouter::missing_rules() );
	}

	public function test_pretty_permalinks_are_possible(): void {
		$GLOBALS['acrossai_test_options'] = array( 'permalink_structure' => '/%postname%/' );

		self::assertTrue( OAuthRouter::rewrites_are_possible() );
	}

	// ---- the two lists cannot drift --------------------------------------

	/**
	 * `register_rewrite_rules()` and `missing_rules()` now read one source.
	 * This asserts they still agree: a rule added to the router must be one
	 * the guard checks for, or the guard silently stops protecting it.
	 */
	public function test_every_registered_rule_is_one_the_guard_checks_for(): void {
		$patterns = $this->registered_patterns();

		$GLOBALS['acrossai_test_options'] = array(
			'permalink_structure' => '/%postname%/',
			'rewrite_rules'       => array( '^wp-json/?$' => 'index.php?rest_route=/' ),
		);

		self::assertSame(
			$patterns,
			OAuthRouter::missing_rules(),
			'With none of them stored, the guard must list every registered rule — in registration order.'
		);
	}

	public function test_there_are_five_rules(): void {
		self::assertCount(
			5,
			$this->registered_patterns(),
			'Changing the rule count is fine; doing it without reading this file is not.'
		);
	}
}
