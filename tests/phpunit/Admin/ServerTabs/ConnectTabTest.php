<?php
/**
 * Tests for ConnectTab — the Feature 084 level-2 connection-method container.
 *
 * Security-critical assertions live here rather than under the user story whose
 * acceptance criteria happen to describe them (bug pattern B55): C2, C3 and C4
 * are implemented in the blocking foundational phase and protect every story,
 * so they are verified alongside it, not behind the release gate.
 *
 * PHPUnit 13+ note (per BUGS.md B9): use `#[DataProvider]` PHP attribute
 * instead of `@dataProvider` annotation — the annotation is silently ignored.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\ServerTabs
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Connect\MethodRegistry;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\ConnectTab;
use WP_UnitTestCase;

final class ConnectTabTest extends WP_UnitTestCase {

	/**
	 * A database-source server row.
	 *
	 * @var array<string, mixed>
	 */
	private array $server = array(
		'id'              => 7,
		'registered_from' => 'database',
		'server_name'     => 'F084 Test Server',
	);

	/**
	 * The per-server Edit screen is `manage_options`-gated, so every method in
	 * these suites is evaluated as an administrator. Without this, C2's
	 * capability pre-filter correctly yields an empty method set.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clears request state and any filter a test registered.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['tab'], $_GET['method'] );
		remove_all_filters( MethodRegistry::FILTER_NAME );
		parent::tear_down();
	}

	/**
	 * Renders the tab and returns its markup.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		( new ConnectTab() )->render( $this->server );
		return (string) ob_get_clean();
	}

	// =========================================================================
	// Identity + navigation (US1).
	// =========================================================================

	/**
	 * Slug, label and priority are the values Registry and Settings key on.
	 */
	public function test_identity(): void {
		$tab = new ConnectTab();
		$this->assertSame( 'connect', $tab->slug() );
		$this->assertSame( 'Connect', $tab->label() );
		$this->assertSame( 20, $tab->priority(), 'Connect sits immediately after Overview (10).' );
	}

	/**
	 * The level-2 nav lists every visible method in priority order.
	 */
	public function test_nav_renders_methods_in_priority_order(): void {
		$html = $this->render();

		$positions = array();
		foreach ( array( 'ai-connectors', 'clients', 'npm', 'wp-cli' ) as $slug ) {
			$pos = strpos( $html, 'method=' . $slug );
			$this->assertNotFalse( $pos, sprintf( 'Method "%s" MUST appear in the nav.', $slug ) );
			$positions[] = $pos;
		}

		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions, 'Methods MUST render in priority order.' );
	}

	/**
	 * The active method is programmatically identifiable (FR-019).
	 */
	public function test_active_method_carries_aria_current(): void {
		$_GET['method'] = 'npm';
		$html           = $this->render();
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ), 'Exactly one method is active.' );
	}

	/**
	 * FR-018 — a single lonely control is worse than none.
	 */
	public function test_nav_suppressed_when_fewer_than_two_methods_visible(): void {
		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				return array_values(
					array_filter(
						$methods,
						static fn ( array $entry ): bool => 'npm' === ( $entry['slug'] ?? '' )
					)
				);
			}
		);

		$html = $this->render();
		$this->assertStringNotContainsString( 'acrossai-connect-methods-nav', $html );
	}

	/**
	 * Zero visible methods → a plain explanatory message, not an empty panel.
	 */
	public function test_explanatory_message_when_no_method_visible(): void {
		add_filter( MethodRegistry::FILTER_NAME, static fn (): array => array() );

		$html = $this->render();
		$this->assertStringContainsString( 'No connection methods are available', $html );
		$this->assertStringNotContainsString( 'acrossai-connect-methods-nav', $html );
	}

	// =========================================================================
	// C1 — the raw-return URL contract.
	// =========================================================================

	/**
	 * `method_url()` returns a RAW url.
	 *
	 * `public/Renderers/MCPClientsBlock.php:146` chains `add_query_arg()` onto
	 * this value; pre-escaping would encode the separator as `&#038;` and break
	 * every level-3 client link. Pins the contract against a future "tidy-up"
	 * that adds `esc_url()` inside the builder.
	 */
	public function test_method_url_returns_raw_unescaped_url(): void {
		$url = ConnectTab::method_url( $this->server, 'clients' );

		$this->assertStringContainsString( '&', $url );
		$this->assertStringNotContainsString( '&#038;', $url, 'method_url() MUST NOT pre-escape — see C1.' );
		$this->assertStringNotContainsString( '&amp;', $url, 'method_url() MUST NOT pre-escape — see C1.' );
		$this->assertStringContainsString( 'tab=connect', $url );
		$this->assertStringContainsString( 'method=clients', $url );
		$this->assertStringContainsString( 'server=7', $url );
	}

	/**
	 * A value chained onto `method_url()` still produces a usable URL — the
	 * behaviour the raw contract exists to protect.
	 */
	public function test_method_url_survives_query_arg_chaining(): void {
		$chained = add_query_arg( 'client', 'cursor', ConnectTab::method_url( $this->server, 'clients' ) );
		$parts   = array();
		parse_str( (string) wp_parse_url( $chained, PHP_URL_QUERY ), $parts );

		$this->assertSame( 'connect', $parts['tab'] ?? null );
		$this->assertSame( 'clients', $parts['method'] ?? null );
		$this->assertSame( 'cursor', $parts['client'] ?? null );
	}

	// =========================================================================
	// C2 — capability filtering precedes resolution AND fallback.
	// =========================================================================

	/**
	 * THE decisive access-control assertion for Feature 084.
	 *
	 * With the first-in-priority method (`ai-connectors`, a PAID one) hidden by
	 * capability, an unqualified request must fall back to a PERMITTED method.
	 * An implementation that filters by capability *after* resolving would
	 * select the hidden method here — silently, and only for restricted users.
	 */
	public function test_fallback_never_selects_a_capability_excluded_method(): void {
		$render_ran = false;

		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ) use ( &$render_ran ): array {
				foreach ( $methods as $i => $entry ) {
					if ( 'ai-connectors' === ( $entry['slug'] ?? '' ) ) {
						$methods[ $i ]['_builtin']        = false;
						$methods[ $i ]['capability']      = 'do_not_grant_this';
						$methods[ $i ]['render_callback'] = static function () use ( &$render_ran ): void {
							$render_ran = true;
							echo 'PAID CONNECTOR BODY';
						};
					}
				}
				return $methods;
			}
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'PAID CONNECTOR BODY', $html );
		$this->assertFalse( $render_ran, 'A capability-excluded method MUST never have its render_callback invoked.' );
		$this->assertStringNotContainsString( 'method=ai-connectors', $html, 'Hidden method MUST NOT appear in the nav.' );
		$this->assertStringContainsString( 'method=clients', $html, 'Fallback MUST land on a permitted method.' );
	}

	/**
	 * Explicitly requesting a capability-excluded method is treated as unknown.
	 */
	public function test_explicit_request_for_excluded_method_falls_back(): void {
		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				foreach ( $methods as $i => $entry ) {
					if ( 'ai-connectors' === ( $entry['slug'] ?? '' ) ) {
						$methods[ $i ]['_builtin']        = false;
						$methods[ $i ]['capability']      = 'do_not_grant_this';
						$methods[ $i ]['render_callback'] = static function (): void {
							echo 'PAID CONNECTOR BODY';
						};
					}
				}
				return $methods;
			}
		);

		$_GET['method'] = 'ai-connectors';
		$html           = $this->render();

		$this->assertStringNotContainsString( 'PAID CONNECTOR BODY', $html );
	}

	// =========================================================================
	// C3 — silent fallback, never reflect the requested value.
	// =========================================================================

	/**
	 * An unknown, removed or excluded method name is never echoed back.
	 *
	 * `sanitize_key()` makes exploitation hard, but "sanitized upstream so it's
	 * fine at output" is exactly the reasoning bug pattern B8 rejects.
	 */
	public function test_requested_method_is_never_reflected(): void {
		$_GET['method'] = 'zzprobezz';
		$html           = $this->render();

		$this->assertStringNotContainsString( 'zzprobezz', $html );
	}

	/**
	 * Unknown and permission-excluded converge on one observable behaviour, so
	 * withheld methods cannot be enumerated.
	 */
	public function test_unknown_and_excluded_are_indistinguishable(): void {
		// One filter, applied to BOTH probes, so the visible set is identical and
		// the only variable is which name was requested. (Comparing across
		// different visible sets would prove nothing — a hidden method legitimately
		// changes what the fallback lands on.)
		$exclude_connectors = static function ( array $methods ): array {
			foreach ( $methods as $i => $entry ) {
				if ( 'ai-connectors' === ( $entry['slug'] ?? '' ) ) {
					$methods[ $i ]['_builtin']        = false;
					$methods[ $i ]['capability']      = 'do_not_grant_this';
					$methods[ $i ]['render_callback'] = static fn () => print( 'PAID CONNECTOR BODY' );
				}
			}
			return $methods;
		};
		add_filter( MethodRegistry::FILTER_NAME, $exclude_connectors );

		$_GET['method'] = 'zzprobezz';
		$unknown        = $this->render();

		$_GET['method'] = 'ai-connectors';
		$excluded       = $this->render();

		$this->assertSame(
			$unknown,
			$excluded,
			'Unknown and capability-excluded MUST be byte-identical, or the difference enumerates withheld methods.'
		);
		$this->assertStringNotContainsString( 'PAID CONNECTOR BODY', $excluded );
	}

	/**
	 * Extracts the active method slug from rendered nav markup.
	 *
	 * @param string $html Rendered markup.
	 * @return string
	 */
	private function active_method_of( string $html ): string {
		return preg_match( '/method=([a-z0-9\-]+)"[^>]*acrossai-connect-method-active/', $html, $m )
			? $m[1]
			: '';
	}

	// =========================================================================
	// C4 — error containment reveals nothing.
	// =========================================================================

	/**
	 * A method that throws is contained, and the message leaks nothing.
	 *
	 * Catches `\Throwable`, not `\Exception`: a `TypeError` from a
	 * mis-registered third-party callback is the likeliest real failure and
	 * would otherwise white-screen the page, defeating FR-013 exactly when it
	 * matters. Exercises C3 and C4 together — an exception message embedding
	 * the requested identifier would breach C3 through C4's failure, which two
	 * tests in separate phases could miss.
	 */
	public function test_throwing_method_is_contained_without_leaking_detail(): void {
		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				$methods[] = array(
					'slug'            => 'boom',
					'label'           => 'Boom',
					'priority'        => 5,
					'render_callback' => static function (): void {
						throw new \TypeError( 'SECRET_DETAIL_/var/www/secret.php' );
					},
				);
				return $methods;
			}
		);

		$_GET['method'] = 'boom';
		$html           = $this->render();

		// Contained, not fatal, and the navigation survives.
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'acrossai-connect-methods-nav', $html );
		$this->assertStringContainsString( 'method=clients', $html );

		// Names the slug only — never the message, path, class or trace.
		$this->assertStringContainsString( 'boom', $html );
		$this->assertStringNotContainsString( 'SECRET_DETAIL', $html );
		$this->assertStringNotContainsString( '/var/www', $html );
		$this->assertStringNotContainsString( 'TypeError', $html );
	}

	// =========================================================================
	// C5 + legacy addresses (US2).
	// =========================================================================

	/**
	 * Every legacy tab address resolves to its method, never to Overview.
	 */
	public function test_legacy_tab_addresses_resolve_to_their_method(): void {
		foreach ( ConnectTab::LEGACY_TAB_METHODS as $legacy_tab => $expected_method ) {
			if ( 'n8n' === $expected_method ) {
				continue; // Supplied by the companion plugin; absent in this suite.
			}

			unset( $_GET['method'] );
			$_GET['tab'] = $legacy_tab;

			$this->assertSame(
				$expected_method,
				$this->active_method_of( $this->render() ),
				sprintf( '?tab=%s MUST open the "%s" method.', $legacy_tab, $expected_method )
			);
		}
	}

	/**
	 * An explicit `?method=` beats the legacy `?tab=` mapping (FR-006).
	 */
	public function test_explicit_method_wins_over_legacy_tab(): void {
		$_GET['tab']    = 'npm';
		$_GET['method'] = 'wp-cli';

		$this->assertSame( 'wp-cli', $this->active_method_of( $this->render() ) );
	}

	/**
	 * SEC-084-T03 — a legacy address MUST NOT bypass the capability gate.
	 *
	 * Resolution step 2 is a different branch from step 1, so a bare
	 * LEGACY_TAB_METHODS lookup that skips the filtered-set re-check would be a
	 * capability bypass reachable by typing an ordinary URL.
	 */
	public function test_legacy_address_cannot_reach_an_excluded_method(): void {
		$render_ran = false;

		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ) use ( &$render_ran ): array {
				foreach ( $methods as $i => $entry ) {
					if ( 'ai-connectors' === ( $entry['slug'] ?? '' ) ) {
						$methods[ $i ]['_builtin']        = false;
						$methods[ $i ]['capability']      = 'do_not_grant_this';
						$methods[ $i ]['render_callback'] = static function () use ( &$render_ran ): void {
							$render_ran = true;
							echo 'PAID CONNECTOR BODY';
						};
					}
				}
				return $methods;
			}
		);

		$_GET['tab'] = 'ai-connectors';
		$html        = $this->render();

		$this->assertFalse( $render_ran, 'A legacy address MUST NOT bypass the capability gate.' );
		$this->assertStringNotContainsString( 'PAID CONNECTOR BODY', $html );
		$this->assertNotSame( 'ai-connectors', $this->active_method_of( $html ) );
	}

	/**
	 * T035 — a legacy address carrying a deeper selection keeps it.
	 *
	 * The level-3 selection (`&client=`, `&panel=`) is owned by each method and
	 * must survive the level-1 → level-2 remap untouched (FR-009).
	 */
	public function test_legacy_deep_link_preserves_level_three_selection(): void {
		$_GET['tab']    = 'clients';
		$_GET['client'] = 'cursor';

		$html = $this->render();

		$this->assertSame( 'clients', $this->active_method_of( $html ) );
		$this->assertSame( 'cursor', $_GET['client'], 'The level-3 arg MUST be left untouched for the method to consume.' );

		unset( $_GET['client'] );
	}

	/**
	 * T035 — a level-3 selection that no longer exists degrades to the method's
	 * own default rather than erroring.
	 */
	public function test_unknown_deep_link_selection_does_not_error(): void {
		$_GET['tab']    = 'clients';
		$_GET['client'] = 'does-not-exist';

		$html = $this->render();

		$this->assertSame( 'clients', $this->active_method_of( $html ) );
		$this->assertStringNotContainsString( 'notice-error', $html );

		unset( $_GET['client'] );
	}

	/**
	 * T037 — resolving a legacy address MUST NOT redirect (FR-009).
	 *
	 * `admin_enqueue_scripts` has already fired by render time, and the
	 * companion gates its assets on the requested address. A redirect would
	 * rewrite the address before those gates saw it.
	 */
	public function test_legacy_resolution_never_redirects(): void {
		$redirects = 0;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirects ) {
				++$redirects;
				return false;
			}
		);

		foreach ( array_keys( ConnectTab::LEGACY_TAB_METHODS ) as $legacy_tab ) {
			$_GET['tab'] = $legacy_tab;
			$this->render();
		}

		remove_all_filters( 'wp_redirect' );
		$this->assertSame( 0, $redirects, 'Legacy addresses MUST resolve in place, never via a redirect.' );
	}

	/**
	 * T046 — on a local install, an unqualified Connect opens MCP Clients.
	 *
	 * Reuses the plugin's existing local detection verbatim (D46); the filter
	 * below is that helper's own documented extension point, not a second
	 * notion of "local".
	 */
	public function test_local_install_defaults_to_clients(): void {
		add_filter( 'acrossai_mcp_local_hostname_suffixes', static fn (): array => array( 'example.org' ) );

		$this->assertSame(
			'clients',
			$this->active_method_of( $this->render() ),
			'D46: a local install opens on MCP Clients.'
		);

		remove_all_filters( 'acrossai_mcp_local_hostname_suffixes' );
	}

	/**
	 * T046 — an explicitly requested method still beats the local default.
	 */
	public function test_explicit_method_beats_local_default(): void {
		add_filter( 'acrossai_mcp_local_hostname_suffixes', static fn (): array => array( 'example.org' ) );

		$_GET['method'] = 'npm';
		$this->assertSame( 'npm', $this->active_method_of( $this->render() ) );

		remove_all_filters( 'acrossai_mcp_local_hostname_suffixes' );
	}

	/**
	 * T047 — the local default MUST still respect the filtered set.
	 *
	 * Step 3 must not become the one branch that skips the C2 gate.
	 */
	public function test_local_default_respects_capability_filter(): void {
		add_filter( 'acrossai_mcp_local_hostname_suffixes', static fn (): array => array( 'example.org' ) );
		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				return array_values(
					array_filter(
						$methods,
						static fn ( array $entry ): bool => 'clients' !== ( $entry['slug'] ?? '' )
					)
				);
			}
		);

		$active = $this->active_method_of( $this->render() );

		$this->assertNotSame( 'clients', $active, 'Step 3 MUST NOT force a method that is not in the filtered set.' );
		$this->assertSame( 'ai-connectors', $active, 'Falls through to step 4 — first permitted method in priority order.' );

		remove_all_filters( 'acrossai_mcp_local_hostname_suffixes' );
	}

	/**
	 * A malformed `?method=` is sanitized, not trusted (C5).
	 */
	public function test_method_query_value_is_sanitized(): void {
		$_GET['method'] = '<script>alert(1)</script>';
		$html           = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html );
	}
}
