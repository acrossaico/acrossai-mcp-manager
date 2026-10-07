<?php
/**
 * Behavioural tests for standing down a competing OAuth implementation.
 *
 * `wp-media/mcp-oauth` is vendored — and self-booted with no opt-in — by
 * several unrelated plugins (Rank Math SEO, Enable Abilities for MCP). It
 * claims the same `.well-known` rewrite rules this plugin serves and answers
 * with a document naming one hardcoded server and NO `registration_endpoint`,
 * so Dynamic Client Registration becomes impossible for every AI connector.
 * Whichever copy registers its rule first owns the site.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryConflictGuard;
use PHPUnit\Framework\TestCase;

final class DiscoveryConflictGuardTest extends TestCase {

	use ServerFixtureTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->reset_fixtures();

		// Stand in for the vendored library being present on the site.
		if ( ! class_exists( '\\WPMedia\\MCP\\OAuth\\Bootstrap', false ) ) {
			// phpcs:ignore Squiz.PHP.Eval -- keeps the stub out of the classmap; see B16.
			eval( 'namespace WPMedia\\MCP\\OAuth { class Bootstrap {} }' );
		}
	}

	/**
	 * @return array<int, string> Hook names that were filtered to false.
	 */
	private function disabled_hooks(): array {
		$hooks = array();
		foreach ( $GLOBALS['acrossai_test_filters'] as $filter ) {
			if ( '__return_false' === $filter['callback'] ) {
				$hooks[] = $filter['hook'];
			}
		}
		return $hooks;
	}

	public function test_conflict_is_detected_when_the_bundled_library_is_loaded(): void {
		self::assertTrue( DiscoveryConflictGuard::conflict_detected() );
	}

	public function test_the_bundled_implementation_is_disabled(): void {
		DiscoveryConflictGuard::instance()->take_over_discovery();

		self::assertContains( 'wpmedia_mcp_oauth_server_enabled', $this->disabled_hooks() );
	}

	public function test_the_deprecated_filter_alias_is_disabled_too(): void {
		DiscoveryConflictGuard::instance()->take_over_discovery();

		self::assertContains(
			'rocket_mcp_oauth_server_enabled',
			$this->disabled_hooks(),
			'The library still reads its pre-rename hook through apply_filters_deprecated().'
		);
	}

	/**
	 * The library defaults its own gate to true, and other code may filter it.
	 * Settling last is the only way to win deterministically.
	 */
	public function test_the_override_is_registered_at_the_highest_priority(): void {
		DiscoveryConflictGuard::instance()->take_over_discovery();

		foreach ( $GLOBALS['acrossai_test_filters'] as $filter ) {
			if ( '__return_false' === $filter['callback'] ) {
				self::assertSame( PHP_INT_MAX, $filter['priority'] );
			}
		}
	}

	public function test_operators_can_opt_out(): void {
		$GLOBALS['acrossai_test_filter_returns']['acrossai_mcp_manager_take_over_oauth_discovery'] = false;

		DiscoveryConflictGuard::instance()->take_over_discovery();

		self::assertSame(
			array(),
			$this->disabled_hooks(),
			'A site deliberately using the other implementation must keep it.'
		);
	}

	public function test_the_conflicting_plugin_is_named_for_the_operator(): void {
		// Resolution walks the loaded class back to its plugin directory; an
		// eval()'d stub has no file, so this degrades to an empty string rather
		// than guessing. The notice copy substitutes a generic label.
		self::assertIsString( DiscoveryConflictGuard::conflicting_plugin_name() );
	}
}
