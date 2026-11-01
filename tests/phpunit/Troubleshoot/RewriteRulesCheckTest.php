<?php
/**
 * The check that answers "why did re-saving Permalinks fix it?".
 *
 * Three states, three different sentences — and the distinction that matters
 * most is between "your rules went missing" (flushing fixes it) and "you have
 * no permalink structure" (flushing can never fix it). Reporting those as the
 * same failure sends the operator to do a thing that will not work.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Troubleshoot;

use AcrossAI_MCP_Manager\Includes\OAuth\OAuthRouter;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\RewriteRulesCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;
use WP_UnitTestCase;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\RewriteRulesCheck
 */
final class RewriteRulesCheckTest extends WP_UnitTestCase {

	/**
	 * Permalink structure to restore afterwards.
	 *
	 * @var string
	 */
	private $original_structure = '';

	/**
	 * Rewrite table to restore afterwards.
	 *
	 * @var mixed
	 */
	private $original_rules;

	public function set_up(): void {
		parent::set_up();

		$this->original_structure = (string) get_option( 'permalink_structure', '' );
		$this->original_rules     = get_option( 'rewrite_rules' );

		update_option( 'permalink_structure', '/%postname%/' );
	}

	public function tear_down(): void {
		update_option( 'permalink_structure', $this->original_structure );
		update_option( 'rewrite_rules', $this->original_rules );

		parent::tear_down();
	}

	private function run_check(): Result {
		return ( new RewriteRulesCheck() )->run( new Context() );
	}

	/**
	 * Build a rewrite table containing every rule the router registers, plus
	 * an unrelated one so the check is not simply counting entries.
	 *
	 * @return array<string, string>
	 */
	private function healthy_table(): array {
		$table = array( '^wp-json/?$' => 'index.php?rest_route=/' );

		foreach ( $this->our_patterns() as $pattern ) {
			$table[ $pattern ] = 'index.php?stored';
		}

		return $table;
	}

	/**
	 * Read the patterns from the router rather than hard-coding them, so a
	 * rule added there cannot leave this test asserting against a stale list.
	 *
	 * @return array<int, string>
	 */
	private function our_patterns(): array {
		update_option( 'rewrite_rules', array() );

		return OAuthRouter::missing_rules();
	}

	public function test_healthy_table_passes(): void {
		update_option( 'rewrite_rules', $this->healthy_table() );

		self::assertSame( Result::OK, $this->run_check()->status() );
	}

	/**
	 * The real-world shape: the option is populated, but it was generated
	 * while this plugin was not loaded, so our rules are simply absent.
	 */
	public function test_a_populated_table_missing_our_rules_fails(): void {
		$table = $this->healthy_table();
		unset( $table['^token/?$'] );
		update_option( 'rewrite_rules', $table );

		$result = $this->run_check()->to_array();

		self::assertSame( Result::FAIL, $result['status'] );
		self::assertStringContainsString( '^token/?$', $result['message'] );
		self::assertNotSame( '', $result['remedy'] );
	}

	public function test_the_failure_names_every_missing_rule(): void {
		update_option( 'rewrite_rules', array( '^wp-json/?$' => 'index.php?rest_route=/' ) );

		$result = $this->run_check()->to_array();

		self::assertSame( Result::FAIL, $result['status'] );

		foreach ( $this->our_patterns() as $pattern ) {
			self::assertStringContainsString( $pattern, $result['message'] );
		}
	}

	/**
	 * Plain permalinks are a different diagnosis. The message must send the
	 * operator to Permalinks to CHOOSE a structure, and must not tell them
	 * the rules are missing — flushing would not create them.
	 */
	public function test_plain_permalinks_report_their_own_cause(): void {
		update_option( 'permalink_structure', '' );
		update_option( 'rewrite_rules', array() );

		$result = $this->run_check()->to_array();

		self::assertSame( Result::FAIL, $result['status'] );
		self::assertStringContainsString( 'plain permalinks', $result['message'] );
		self::assertStringContainsString( 'options-permalink.php', $result['remedy'] );
		self::assertStringNotContainsString( 'missing from the stored rewrite table', $result['message'] );
	}

	public function test_the_check_writes_nothing(): void {
		update_option( 'rewrite_rules', $this->healthy_table() );

		$before = get_option( 'rewrite_rules' );
		$this->run_check();

		self::assertSame( $before, get_option( 'rewrite_rules' ) );
	}
}
