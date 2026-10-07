<?php
/**
 * The default server's `is_enabled` column must actually govern the endpoint.
 *
 * `register_database_servers()` only registers rows whose
 * `registered_from = 'database'`. The default row is `registered_from =
 * 'plugin'` — the adapter's own DefaultServerFactory creates it, and the
 * `mcp_adapter_create_default_server` filter is its only off-switch. Nothing
 * hooked that filter, so Disable was cosmetic: the row flipped to 0, the badge
 * read "Inactive", and the route kept serving. Measured as an authenticated
 * admin against a server marked Inactive — `initialize` returned HTTP 200 and
 * `tools/list` returned 4 tools.
 *
 * It also defeated the plugin's own stated default: DefaultServerSeeder has
 * always seeded `is_enabled => 0`, so a fresh install published an endpoint the
 * plugin had declared disabled.
 *
 * The subtle half is WHAT MUST NOT be suppressed. The adapter gates three
 * things on that single filter, at three different moments — the ability
 * CATEGORY on `wp_abilities_api_categories_init`, the three `mcp-adapter/*`
 * ABILITIES on `wp_abilities_api_init`, and the SERVER itself during its own
 * init. Only the server may be suppressed: those abilities are site-wide
 * primitives that sibling servers advertise through their `tool_*` columns, and
 * a tool naming an ability that does not exist is what logged thousands of
 * "WordPress ability 'mcp-adapter/get-ability-info' does not exist" errors
 * under adapter 0.6.1.
 *
 * The category is the easy one to miss, and was missed: a first cut guarded
 * only the abilities hook, which silently took the category with it and left
 * the abilities unregistrable — a sibling server's `tools/list` fell from 4
 * tools to 1, and 15 "does not exist" errors landed in one minute. Hence a test
 * per hook rather than one covering "the ability hooks".
 *
 * @package AcrossAI_MCP_Manager\Tests\MCP
 */

namespace AcrossAI_MCP_Manager\Tests\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\MCP\Controller;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

final class DefaultServerEnablementTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->truncate_mcp_server_table();
	}

	public function tearDown(): void {
		$this->truncate_mcp_server_table();
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// The switch itself.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_a_disabled_default_row_suppresses_the_server(): void {
		$this->seed_default_row( 0 );

		$this->assertFalse(
			Controller::instance()->filter_create_default_server( true ),
			'is_enabled = 0 must stop the adapter creating the default server, or Disable is cosmetic.'
		);
	}

	public function test_an_enabled_default_row_allows_the_server(): void {
		$this->seed_default_row( 1 );

		$this->assertTrue(
			Controller::instance()->filter_create_default_server( true ),
			'is_enabled = 1 must leave the vendor free to create the default server.'
		);
	}

	public function test_a_missing_row_defers_to_the_vendor_default(): void {
		// Unseeded install or a table that is not ready. Pulling an endpoint
		// down over a transient DB condition would be worse than leaving the
		// vendor default in place, so this branch fails TO the vendor.
		$this->assertTrue(
			Controller::instance()->filter_create_default_server( true ),
			'No default row: pass the vendor value through unchanged.'
		);
		$this->assertFalse(
			Controller::instance()->filter_create_default_server( false ),
			'No default row: a vendor "false" must also pass through unchanged.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// What must survive being switched off. One test per hook, deliberately:
	// the category hook is a separate call site and was the one missed.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_disabling_the_server_still_registers_the_ability_category(): void {
		$this->seed_default_row( 0 );

		$this->assertTrue(
			$this->filter_answer_during( 'wp_abilities_api_categories_init' ),
			'The mcp-adapter ability CATEGORY must still register. Without it the three mcp-adapter/* abilities cannot register at all, so sibling servers advertising them break.'
		);
	}

	public function test_disabling_the_server_still_registers_the_default_abilities(): void {
		$this->seed_default_row( 0 );

		$this->assertTrue(
			$this->filter_answer_during( 'wp_abilities_api_init' ),
			'The three mcp-adapter/* abilities are site-wide primitives, not the default server\'s property. Disabling one server must not unregister them.'
		);
	}

	public function test_the_filter_is_actually_wired(): void {
		$this->assertNotFalse(
			has_filter( 'mcp_adapter_create_default_server' ),
			'Main::define_admin_hooks() MUST hook mcp_adapter_create_default_server — unhooked, the whole switch is inert and Disable silently does nothing.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Ask the filter what it answers while a given hook is mid-flight.
	 *
	 * `doing_action()` is the thing under test and is only true inside a real
	 * `do_action()`, so the probe has to run from within one rather than being
	 * called directly.
	 *
	 * The hook is fired with ONLY the probe attached. Running the real
	 * subscribers would re-register what the bootstrap already registered, and
	 * the ability-category registry emits `_doing_it_wrong` on a duplicate —
	 * which `WP_UnitTestCase` turns into a failure having nothing to do with
	 * the assertion ("Ability category \"acrossai-mcp\" is already
	 * registered"). Detaching keeps the probe honest and the hook side-effect
	 * free; `do_action()` still sets `$wp_current_filter`, which is all
	 * `doing_action()` reads.
	 *
	 * @param string $hook Hook to fire.
	 * @return bool The filter's answer, captured from inside the hook.
	 */
	private function filter_answer_during( string $hook ): bool {
		global $wp_filter;

		$saved = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
		unset( $wp_filter[ $hook ] );

		$answer = null;

		add_action(
			$hook,
			static function () use ( &$answer ) {
				$answer = Controller::instance()->filter_create_default_server( true );
			}
		);

		try {
			do_action( $hook );
		} finally {
			unset( $wp_filter[ $hook ] );
			if ( null !== $saved ) {
				$wp_filter[ $hook ] = $saved;
			}
		}

		$this->assertNotNull( $answer, sprintf( 'The probe never ran — "%s" did not fire, so this test proved nothing.', $hook ) );

		return (bool) $answer;
	}

	private function seed_default_row( int $is_enabled ): void {
		MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Default MCP Server',
				'server_slug'            => DefaultServerSeeder::SLUG,
				'description'            => 'Seeded by DefaultServerEnablementTest',
				'is_enabled'             => $is_enabled,
				'registered_from'        => 'plugin',
				'server_route_namespace' => 'mcp',
				'server_route'           => DefaultServerSeeder::SLUG,
				'server_version'         => 'v1.0.0',
			)
		);
	}

	private function truncate_mcp_server_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}
}
