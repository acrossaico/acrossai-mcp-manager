<?php
/**
 * Tests for Registry — per-server-edit tab dispatch.
 *
 * Feature 013. PHPUnit 13+ note (per BUGS.md B9): use `#[DataProvider]` PHP
 * attribute instead of `@dataProvider` annotation — the annotation is
 * silently ignored.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\ServerTabs
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\ClientsTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\FilteredServerTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\NpmTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry;
use WP_UnitTestCase;

final class RegistryTest extends WP_UnitTestCase {

	/**
	 * Removes any filter callback the current test registered on
	 * Registry::FILTER_NAME so subsequent tests see a clean baseline.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( Registry::FILTER_NAME );
		parent::tear_down();
	}

	/**
	 * Verifies Registry::instance() returns a singleton.
	 */
	public function test_instance_returns_singleton(): void {
		$one = Registry::instance();
		$two = Registry::instance();
		$this->assertSame( $one, $two );
	}

	/**
	 * Verifies all_tabs() returns the registered built-in top-level tabs.
	 *
	 * Post-F037 added Embeds tab; post-F040 added AIConnectorsPromoTab as a
	 * built-in placeholder (companion overrides via last-wins dedup when active).
	 * 0.2.10 removed EmbedsTab from the built-in list (tab hidden). The class
	 * file is retained; re-enable by re-adding to Registry::all_tabs() +
	 * uncommenting EmbedsTab::register() in Main::define_public_hooks().
	 *
	 * F084 took the list 11 -> 8: `npm`, `clients`, `ai-connectors` and
	 * `wp-cli` moved one level down into ConnectTab's `?method=` navigation.
	 * The four classes still exist and are still instantiable — they are now
	 * seeded by `Connect\MethodRegistry::all_methods()` instead.
	 *
	 * The count is DERIVED from `all_tabs()` rather than hardcoded: per bug
	 * pattern B48, re-hardcoding a literal guarantees this test breaks again at
	 * the next tab change, with a message that does not point at the cause.
	 */
	public function test_slug_ordering_final(): void {
		$all_tabs = Registry::instance()->all_tabs();
		$slugs    = array_map(
			static function ( $tab ) {
				return $tab->slug();
			},
			$all_tabs
		);
		$this->assertContains( 'overview', $slugs );
		$this->assertContains( 'connect', $slugs );
		$this->assertContains( 'tools', $slugs );
		$this->assertContains( 'abilities', $slugs );
		$this->assertContains( 'access-control', $slugs );
		$this->assertContains( 'mcp-log', $slugs );
		$this->assertContains( 'update-server', $slugs );
		$this->assertContains( 'danger-zone', $slugs );
		$this->assertNotContains( 'embeds', $slugs, '0.2.10 hid the Embeds tab from the built-in list.' );

		// F084 — the five connection slugs are level-2 methods now, never
		// top-level tabs. This is the assertion that fails if a future change
		// re-registers one of them at level 1.
		foreach ( array( 'npm', 'clients', 'ai-connectors', 'n8n', 'wp-cli' ) as $method_slug ) {
			$this->assertNotContains(
				$method_slug,
				$slugs,
				sprintf( 'F084: "%s" is a Connect method, not a top-level tab.', $method_slug )
			);
		}

		$this->assertCount( count( $all_tabs ), $slugs );
	}

	/**
	 * Slug uniqueness — no two tabs share the same slug.
	 */
	public function test_slug_uniqueness(): void {
		$slugs = array_map(
			static function ( $tab ) {
				return $tab->slug();
			},
			Registry::instance()->all_tabs()
		);
		$this->assertSame( count( $slugs ), count( array_unique( $slugs ) ) );
	}

	/**
	 * DB-only tabs (UpdateServer, DangerZone) are visible ONLY when
	 * $server['registered_from'] === 'database'.
	 */
	public function test_visible_tabs_gates_db_only_on_plugin_source(): void {
		$visible = Registry::instance()->visible_tabs(
			array(
				'id'              => 1,
				'registered_from' => 'plugin',
			)
		);
		$slugs = array_map(
			static function ( $tab ) {
				return $tab->slug();
			},
			$visible
		);
		$this->assertNotContains( 'update-server', $slugs );
		$this->assertNotContains( 'danger-zone', $slugs );
	}

	/**
	 * DB-only tabs visible when registered_from = database.
	 */
	public function test_visible_tabs_includes_db_only_on_database_source(): void {
		$visible = Registry::instance()->visible_tabs(
			array(
				'id'              => 2,
				'registered_from' => 'database',
			)
		);
		$slugs = array_map(
			static function ( $tab ) {
				return $tab->slug();
			},
			$visible
		);
		$this->assertContains( 'update-server', $slugs );
		$this->assertContains( 'danger-zone', $slugs );
	}

	/**
	 * Verifies render() with an unknown slug does NOT fatal (silent no-op
	 * when all_tabs() is empty; fallback to first tab post-T012).
	 */
	public function test_render_unknown_slug_falls_back_gracefully(): void {
		$this->expectNotToPerformAssertions();
		// OverviewTab (the $tabs[0] fallback) reads $server['server_name'];
		// the fixture must supply it. Pre-existing gap, repaired in F084.
		Registry::instance()->render( 'unknown-slug', array(
				'id'                    => 1,
				'registered_from'       => 'database',
				'server_name'           => 'Fixture Server',
				'server_slug'           => 'fixture-server',
				'server_version'        => 'v1.0.0',
				'server_route_namespace' => 'acrossai/v1',
				'server_route'          => 'mcp',
				'description'           => 'Fixture',
				'is_enabled'            => 1,
			) );
	}

	/**
	 * Plugin-source servers see every built-in EXCEPT UpdateServer and
	 * DangerZone, which are database-source only.
	 *
	 * Counts derived from `all_tabs()` rather than hardcoded (B48) — F084 took
	 * the built-in list 11 -> 8 and this assertion should not need editing
	 * again at the next tab change.
	 */
	public function test_visible_tabs_excludes_db_only_tabs_when_plugin_source(): void {
		$visible = Registry::instance()->visible_tabs(
			array(
				'id'              => 1,
				'registered_from' => 'plugin',
			)
		);
		$this->assertCount( count( Registry::instance()->all_tabs() ) - 2, $visible );
	}

	/**
	 * Database-source servers see the full canonical set.
	 */
	public function test_visible_tabs_returns_full_set_when_database_source(): void {
		$visible = Registry::instance()->visible_tabs(
			array(
				'id'              => 2,
				'registered_from' => 'database',
			)
		);
		$this->assertCount( count( Registry::instance()->all_tabs() ), $visible );
	}

	// =========================================================================
	// Feature 019 — third-party filter tests.
	// =========================================================================

	/**
	 * The filter fires with the seeded built-in list AND the current
	 * $server row as the two arguments.
	 */
	public function test_for_server_fires_filter_with_server_context(): void {
		$captured_server = null;
		$captured_count  = 0;

		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs, array $server ) use ( &$captured_server, &$captured_count ): array {
				$captured_server = $server;
				$captured_count  = count( $tabs );
				return $tabs;
			},
			10,
			2
		);

		$server = array(
			'id'              => 42,
			'registered_from' => 'database',
			'server_name'     => 'FeatureTest Server',
		);
		Registry::instance()->for_server( $server );

		$this->assertSame( $server, $captured_server, 'Filter must receive the exact $server argument.' );
		$this->assertSame(
			count( Registry::instance()->all_tabs() ),
			$captured_count,
			'Filter must be seeded with exactly the built-in entries.'
		);
	}

	/**
	 * With no callback registered, `for_server()` returns the built-ins in
	 * canonical priority order (priority ASC, insertion-order tiebreak).
	 */
	public function test_for_server_returns_builtins_when_no_callback(): void {
		$tabs = Registry::instance()->for_server(
			array(
				'id'              => 1,
				'registered_from' => 'database',
			)
		);

		$slugs = array_map( static fn ( $t ) => $t->slug(), $tabs );

		$this->assertSame(
			array(
				'overview',       // 10
				'connect',        // 20 — F084 container for the five connection methods
				'tools',          // 50
				'abilities',      // 60
				'access-control', // 70
				'mcp-log',        // 80
				'update-server',  // 90 (EmbedsTab at 90 removed in 0.2.10 — only entry at this slot now)
				'danger-zone',    // 100
			),
			$slugs,
			'Canonical priority order MUST be preserved when no callback runs.'
		);
	}

	/**
	 * F040 last-wins dedup — a filter callback registering an entry with the
	 * same slug as a built-in REPLACES the built-in (rather than being
	 * silently rejected as it was pre-F040). Enables the built-in-placeholder
	 * → companion-override pattern used by AIConnectorsPromoTab.
	 */
	public function test_filter_last_registration_replaces_earlier_same_slug(): void {
		$override_invoked = false;

		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ) use ( &$override_invoked ): array {
				$tabs[] = array(
					'slug'            => 'overview', // Same slug as OverviewTab built-in.
					'label'           => 'Overridden Overview',
					'priority'        => 10,
					'render_callback' => static function () use ( &$override_invoked ): void {
						$override_invoked = true;
						echo 'overridden overview body';
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$server = array(
			'id'              => 1,
			'registered_from' => 'database',
		);
		$tabs   = Registry::instance()->for_server( $server );

		// Find the 'overview' entry in the effective list.
		$overview = null;
		foreach ( $tabs as $tab ) {
			if ( 'overview' === $tab->slug() ) {
				$overview = $tab;
				break;
			}
		}
		$this->assertNotNull( $overview, 'Overview slug MUST still be present after override.' );
		$this->assertSame( 'Overridden Overview', $overview->label(), 'F040 last-wins: filter contribution MUST replace the built-in label.' );

		// Slug uniqueness invariant still holds — no duplicate 'overview' entries.
		$slugs = array_map( static fn ( $t ) => $t->slug(), $tabs );
		$this->assertSame(
			count( $slugs ),
			count( array_unique( $slugs ) ),
			'F040 last-wins MUST maintain slug uniqueness in the effective list.'
		);

		// Dispatch via render() MUST hit the override callback, not the built-in.
		ob_start();
		Registry::instance()->render( 'overview', $server );
		$body = ob_get_clean();

		$this->assertTrue( $override_invoked, 'render() MUST dispatch to the override callback.' );
		$this->assertStringContainsString( 'overridden overview body', $body );
	}

	/**
	 * A callback can append a third-party entry; it appears in
	 * for_server(), sits at the priority-determined position, and dispatches
	 * via render() to its callback.
	 */
	public function test_filter_can_add_a_tab(): void {
		$callback_invoked = false;

		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ) use ( &$callback_invoked ): array {
				$tabs[] = array(
					'slug'            => 'notes',
					'label'           => 'Notes',
					'priority'        => 45,
					'render_callback' => static function () use ( &$callback_invoked ): void {
						$callback_invoked = true;
						echo 'notes tab body';
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$server = array(
			'id'              => 1,
			'registered_from' => 'database',
		);
		$tabs   = Registry::instance()->for_server( $server );
		$slugs  = array_map( static fn ( $t ) => $t->slug(), $tabs );

		$this->assertContains( 'notes', $slugs, 'Third-party slug MUST appear in for_server() output.' );

		// Priority 45 slots between ConnectTab (20) and ToolsTab (50).
		// Pre-F084 the lower neighbour was WpCliTab (40), which is now a
		// level-2 Connect method rather than a top-level tab.
		$notes_index   = array_search( 'notes', $slugs, true );
		$connect_index = array_search( 'connect', $slugs, true );
		$tools_index   = array_search( 'tools', $slugs, true );
		$this->assertGreaterThan( $connect_index, $notes_index );
		$this->assertLessThan( $tools_index, $notes_index );

		ob_start();
		Registry::instance()->render( 'notes', $server );
		$body = ob_get_clean();

		$this->assertTrue( $callback_invoked, 'render() MUST dispatch to the third-party callback.' );
		$this->assertStringContainsString( 'notes tab body', $body );
	}

	/**
	 * A callback can unset a built-in entry; it disappears from
	 * for_server() and render() falls through to the first tab when
	 * the removed slug is requested.
	 */
	public function test_filter_can_remove_a_builtin(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				return array_values(
					array_filter(
						$tabs,
						static fn ( array $entry ): bool => 'mcp-log' !== ( $entry['slug'] ?? '' )
					)
				);
			},
			10,
			2
		);

		// OverviewTab is the $tabs[0] fallback rendered below; it reads the full
		// server row. Pre-existing fixture gap, repaired in F084.
		$server = array(
				'id'                    => 1,
				'registered_from'       => 'database',
				'server_name'           => 'Fixture Server',
				'server_slug'           => 'fixture-server',
				'server_version'        => 'v1.0.0',
				'server_route_namespace' => 'acrossai/v1',
				'server_route'          => 'mcp',
				'description'           => 'Fixture',
				'is_enabled'            => 1,
			);
		$slugs  = array_map( static fn ( $t ) => $t->slug(), Registry::instance()->for_server( $server ) );

		$this->assertNotContains( 'mcp-log', $slugs );
		// Derived, not hardcoded (B48): one built-in was removed by the filter.
		$this->assertCount( count( Registry::instance()->all_tabs() ) - 1, $slugs );

		// Unknown-slug render() falls back to the first surviving tab.
		ob_start();
		Registry::instance()->render( 'mcp-log', $server );
		$body = ob_get_clean();
		$this->assertIsString( $body );
	}

	/**
	 * A third-party priority below any built-in makes the tab render leftmost.
	 */
	public function test_filter_can_reorder_via_priority(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'            => 'leftmost',
					'label'           => 'Leftmost',
					'priority'        => 5,
					'render_callback' => static function (): void {
						echo 'leftmost body';
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$slugs = array_map(
			static fn ( $t ) => $t->slug(),
			Registry::instance()->for_server( array( 'id' => 1, 'registered_from' => 'database' ) )
		);

		$this->assertSame( 'leftmost', $slugs[0], 'Priority 5 MUST sort before all built-ins.' );
	}

	/**
	 * A malformed entry (missing render_callback) is dropped without a
	 * fatal — `_doing_it_wrong` fires under WP_DEBUG but the outer request
	 * continues.
	 */
	public function test_malformed_entry_dropped_without_fatal(): void {
		$this->setExpectedIncorrectUsage( Registry::FILTER_NAME );

		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'  => 'bad',
					'label' => 'Bad',
					// intentionally omit render_callback
				);
				return $tabs;
			},
			10,
			2
		);

		$slugs = array_map(
			static fn ( $t ) => $t->slug(),
			Registry::instance()->for_server( array( 'id' => 1, 'registered_from' => 'database' ) )
		);

		$this->assertNotContains( 'bad', $slugs );
	}

	/**
	 * Duplicate slug → the LATER registration wins (D41 / F040 follow-up).
	 *
	 * Renamed and inverted in F084. This test previously asserted
	 * first-registration-wins and had been failing since F040 changed the
	 * semantics: the built-in placeholder → companion override pattern
	 * DEPENDS on last-wins, and `Registry`'s own class docblock documents it.
	 * The code was right; the assertion was stale.
	 *
	 * This is the exact mechanism by which acrossai-pro replaces the free
	 * `AIConnectorsPromoTab` — now one level down, on the Connect method
	 * registry, through the same shared normalizer.
	 */
	public function test_duplicate_slug_last_registration_wins(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'            => 'overview',
					'label'           => 'OVERRIDDEN',
					'render_callback' => static function (): void {
						echo 'overridden body';
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$tabs = Registry::instance()->for_server(
			array(
				'id'              => 1,
				'registered_from' => 'database',
			)
		);

		$overview = null;
		foreach ( $tabs as $t ) {
			if ( 'overview' === $t->slug() ) {
				$overview = $t;
				break;
			}
		}

		$this->assertNotNull( $overview );
		$this->assertInstanceOf(
			FilteredServerTab::class,
			$overview,
			'D41: the later registration REPLACES the built-in instance.'
		);
		$this->assertSame( 'OVERRIDDEN', $overview->label() );

		$slugs = array_map( static fn ( $t ) => $t->slug(), $tabs );
		$this->assertSame( 1, array_count_values( $slugs )['overview'], 'Slug MUST appear exactly once.' );
	}

	/**
	 * A third-party render_callback throwing MUST NOT propagate to
	 * Registry::render(); the outer request continues with an inline
	 * error notice rendered in-place.
	 */
	public function test_throwing_render_callback_is_isolated(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'            => 'broken',
					'label'           => 'Broken',
					'priority'        => 999,
					'render_callback' => static function (): void {
						throw new \RuntimeException( 'boom' );
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$server = array(
			'id'              => 1,
			'registered_from' => 'database',
		);

		ob_start();
		try {
			Registry::instance()->render( 'broken', $server );
			$body     = ob_get_clean();
			$fataled  = false;
		} catch ( \Throwable $t ) {
			ob_end_clean();
			$body    = '';
			$fataled = true;
		}

		$this->assertFalse( $fataled, 'A throwing render_callback MUST NOT propagate to Registry::render().' );
		$this->assertStringContainsString( 'notice-error', $body, 'Inline error notice MUST be rendered.' );
	}

	/**
	 * FR-016 / T052 — a third-party tab with a NON-connection slug is unaffected
	 * by the F084 merge and still appears at the top level.
	 */
	public function test_non_connection_third_party_tab_still_renders_top_level(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'            => 'billing',
					'label'           => 'Billing',
					'priority'        => 45,
					'render_callback' => static fn () => print( 'billing body' ),
				);
				return $tabs;
			},
			10,
			2
		);

		$slugs = array_map(
			static fn ( $t ) => $t->slug(),
			Registry::instance()->for_server(
				array(
					'id'              => 1,
					'registered_from' => 'database',
				)
			)
		);

		$this->assertContains( 'billing', $slugs, 'FR-016: the tab extension point still serves non-connection slugs.' );
	}

	/**
	 * S1 / T030 — the npm and MCP Clients renderers keep their nonce action
	 * after F084 repointed their `submit_target_url` to the level-2 builder.
	 *
	 * Asserted on the renderer CONTEXT rather than on rendered markup: the
	 * nonce field is emitted further downstream and only on some paths, whereas
	 * `nonce_action` is the actual binding this feature could have broken. The
	 * test also pins the positive half — that `submit_target_url` really did
	 * move to the Connect method URL — so it cannot pass vacuously.
	 */
	public function test_moved_forms_keep_nonce_action_while_target_url_moves(): void {
		$server = array(
			'id'              => 7,
			'registered_from' => 'database',
		);

		$expected_action = 'acrossai_mcp_manager_server_' . (int) $server['id'];
		$captured        = array();

		add_filter(
			'acrossai_mcp_client_block_context',
			static function ( $context ) use ( &$captured ) {
				$captured[] = (array) $context;
				return $context;
			}
		);

		foreach ( array( ClientsTab::class, NpmTab::class ) as $tab_class ) {
			ob_start();
			( new $tab_class() )->render( $server );
			ob_get_clean();
		}

		remove_all_filters( 'acrossai_mcp_client_block_context' );

		$this->assertNotEmpty( $captured, 'The renderer context filter MUST fire for both migrated tabs.' );

		foreach ( $captured as $context ) {
			$this->assertSame(
				$expected_action,
				$context['nonce_action'] ?? null,
				'S1: the nonce action MUST be unchanged by the F084 target-URL repoint.'
			);
			$this->assertStringContainsString(
				'tab=connect',
				(string) ( $context['submit_target_url'] ?? '' ),
				'The target URL MUST have moved to the Connect method builder — otherwise this test passes vacuously.'
			);
		}
	}

}
