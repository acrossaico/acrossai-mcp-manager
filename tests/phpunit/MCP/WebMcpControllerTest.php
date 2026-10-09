<?php
/**
 * F093 — WebMcpController + WebMCP\Settings.
 *
 * Two things here earn their keep.
 *
 * THE NAME DERIVATION IS A FOREVER-CONTRACT. WebMCP tool names are what an
 * agent remembers between sessions — an agent that has seen this site before
 * will reuse the name it learned. Changing the derivation rule after release
 * silently breaks every returning agent, with no error anywhere: the old name
 * simply stops existing. So the rule is pinned by example, not described.
 *
 * SETTINGS MUST FAIL CLOSED. `selected_row()` is the single place that decides
 * whether WebMCP is usable at all, and every one of its refusal branches is a
 * case where the alternative is worse than an error — most of all a dangling
 * selection quietly resolving to a different server than the admin chose.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\MCP
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\REST\WebMcpController;
use AcrossAI_MCP_Manager\Includes\WebMCP\BrowserEligibility;
use AcrossAI_MCP_Manager\Includes\WebMCP\Settings as WebMcpSettings;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

final class WebMcpControllerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->truncate_servers();
		delete_option( WebMcpSettings::OPTION_ENABLED );
		delete_option( WebMcpSettings::OPTION_SERVER );
		delete_option( WebMcpSettings::OPTION_ALLOW_EXECUTE );
	}

	public function tearDown(): void {
		$this->truncate_servers();
		delete_option( WebMcpSettings::OPTION_ENABLED );
		delete_option( WebMcpSettings::OPTION_SERVER );
		delete_option( WebMcpSettings::OPTION_ALLOW_EXECUTE );
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Tool names. Pinned by example — see the file docblock.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @dataProvider provide_tool_names
	 */
	public function test_tool_names_are_derived_by_one_stable_rule( string $slug, string $expected ): void {
		$this->assertSame(
			$expected,
			WebMcpController::tool_name( $slug ),
			'The WebMCP name derivation changed. Agents reuse names they learned on a previous '
				. 'visit, so a changed rule breaks returning agents silently — the old name just '
				. 'stops existing. Change this only with a deliberate migration story.'
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_tool_names(): array {
		return array(
			'toolset dispatcher'  => array( 'toolset/content', 'wp_toolset_content' ),
			'hyphenated vendor'   => array( 'mcp-adapter/execute-ability', 'wp_mcp_adapter_execute_ability' ),
			'server guide'        => array( 'toolset/server-guide', 'wp_toolset_server_guide' ),
			'already identifier'  => array( 'something_plain', 'wp_something_plain' ),
			'uppercase collapsed' => array( 'Toolset/Content', 'wp_toolset_content' ),
		);
	}

	public function test_tool_names_are_identifier_shaped(): void {
		// WebMCP names may not contain a slash. Asserted as a property rather
		// than by example so a future slug shape cannot sneak one through.
		foreach ( array( 'toolset/content', 'mcp-adapter/get-ability-info', 'a/b/c' ) as $slug ) {
			$this->assertMatchesRegularExpression(
				'/^wp_[a-z0-9_]+$/',
				WebMcpController::tool_name( $slug ),
				sprintf( 'Derived name for "%s" is not identifier-shaped.', $slug )
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Settings resolution, fail closed.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_the_beta_is_off_by_default(): void {
		$this->assertFalse(
			WebMcpSettings::is_enabled(),
			'WebMCP must ship off. Registering tools in the browser is opt-in.'
		);
	}

	public function test_an_empty_selection_resolves_to_the_recommended_server(): void {
		$this->assertSame(
			DefaultServerSeeder::ACROSSAI_SLUG,
			WebMcpSettings::selected_slug(),
			'An unset option must resolve lazily to the recommended server rather than being '
				. 'written at activation — a concrete value stored up front goes stale when the '
				. 'row is reseeded or the environment is migrated.'
		);
	}

	public function test_no_row_is_returned_while_the_beta_is_off(): void {
		$this->seed_server( DefaultServerSeeder::ACROSSAI_SLUG, 1 );

		$this->assertNull(
			WebMcpSettings::selected_row(),
			'The server exists and is enabled, but the beta gate is off — nothing should resolve.'
		);
	}

	public function test_a_disabled_server_is_not_reachable_from_the_browser(): void {
		update_option( WebMcpSettings::OPTION_ENABLED, true );
		$this->seed_server( DefaultServerSeeder::ACROSSAI_SLUG, 0 );

		$this->assertNull(
			WebMcpSettings::selected_row(),
			'A disabled server is unreachable remotely; it must not become reachable from the '
				. 'browser. Returning it here would make Disable cosmetic for WebMCP.'
		);
	}

	public function test_a_missing_row_does_not_fall_back_to_another_server(): void {
		update_option( WebMcpSettings::OPTION_ENABLED, true );
		update_option( WebMcpSettings::OPTION_SERVER, 'deleted-server' );
		// A different, perfectly healthy server exists — the tempting fallback.
		$this->seed_server( DefaultServerSeeder::ACROSSAI_SLUG, 1 );

		$this->assertNull(
			WebMcpSettings::selected_row(),
			'A dangling selection silently resolving to a DIFFERENT server is the specific '
				. 'failure this guards: the admin would be exposing a server they never chose.'
		);
	}

	public function test_an_enabled_selected_server_resolves(): void {
		update_option( WebMcpSettings::OPTION_ENABLED, true );
		$this->seed_server( DefaultServerSeeder::ACROSSAI_SLUG, 1 );

		$row = WebMcpSettings::selected_row();

		$this->assertNotNull( $row, 'A healthy, enabled, selected server must resolve.' );
		$this->assertSame( DefaultServerSeeder::ACROSSAI_SLUG, (string) $row->server_slug );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Describing tools.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_an_unregistered_slug_is_omitted_rather_than_advertised(): void {
		$this->assertNull(
			WebMcpController::describe_tool( 'no-such/ability-' . wp_generate_uuid4() ),
			'A curated tool row can outlive the plugin that registered its ability. Advertising '
				. 'a tool that cannot run is worse than omitting it — an agent caches the tool '
				. 'list when it connects, so the broken entry persists for the whole session.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Safety rails.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_running_tools_is_off_even_once_webmcp_is_on(): void {
		update_option( WebMcpSettings::OPTION_ENABLED, true );

		$this->assertFalse(
			WebMcpSettings::allows_execute(),
			'Enabling WebMCP must publish the catalogue WITHOUT granting the right to act on the '
				. 'site. The two decisions are separate on purpose: an operator can confirm the '
				. 'wiring works before anything can change their content.'
		);
	}

	public function test_an_ability_may_veto_itself_out_of_the_browser(): void {
		$this->assertFalse(
			BrowserEligibility::is_allowed( 'some/ability', array( 'webmcp' => false ) ),
			'meta.webmcp = false is a hard veto for ability authors who know their ability is '
				. 'unsafe in a page, where any in-page agent can call it with the admin\'s cookie.'
		);

		$this->assertFalse(
			BrowserEligibility::is_allowed( 'some/ability', array( 'webmcp' => array( 'enabled' => false ) ) ),
			'The long form must veto too — it exists so the key can grow a `confirm` option later '
				. 'without breaking anyone already using the boolean.'
		);
	}

	public function test_abilities_are_eligible_by_default(): void {
		// Deliberately NOT Laravel's default-hidden. Our abilities come from
		// plugins we do not author and the operator has already curated them
		// on the Tools tab; defaulting closed would make the recommended
		// server publish nothing, since none of its fourteen toolset/*
		// dispatchers declares anything about WebMCP.
		$this->assertTrue(
			BrowserEligibility::is_allowed( 'toolset/content', array() ),
			'An ability with no WebMCP declaration must inherit the admin\'s curation. Defaulting '
				. 'closed would silently publish nothing while appearing to work.'
		);
	}

	public function test_the_eligibility_filter_can_narrow(): void {
		$deny = static fn( $allowed, $slug ) => 'toolset/files' === $slug ? false : $allowed;
		add_filter( 'acrossai_mcp_webmcp_ability_allowed', $deny, 10, 2 );

		$this->assertFalse( BrowserEligibility::is_allowed( 'toolset/files', array() ) );
		$this->assertTrue( BrowserEligibility::is_allowed( 'toolset/content', array() ) );

		remove_filter( 'acrossai_mcp_webmcp_ability_allowed', $deny, 10 );
	}

	public function test_changing_the_server_is_announced(): void {
		update_option( WebMcpSettings::OPTION_SERVER, 'first-server' );

		$seen = array();
		$spy  = static function ( $new, $old ) use ( &$seen ) {
			$seen[] = array( $new, $old );
		};
		add_action( 'acrossai_mcp_webmcp_server_changed', $spy, 10, 2 );

		update_option( WebMcpSettings::OPTION_SERVER, 'second-server' );

		remove_action( 'acrossai_mcp_webmcp_server_changed', $spy, 10 );

		$this->assertSame(
			array( array( 'second-server', 'first-server' ) ),
			$seen,
			'Switching servers changes the tool NAMES, not merely what sits behind them — an '
				. 'agent mid-session finds the names it learned have vanished. Nothing else '
				. 'records that the ground moved.'
		);
	}

	public function test_a_no_op_save_announces_nothing(): void {
		update_option( WebMcpSettings::OPTION_SERVER, 'same-server' );

		$fired = 0;
		$spy   = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'acrossai_mcp_webmcp_server_changed', $spy, 10, 2 );

		update_option( WebMcpSettings::OPTION_SERVER, 'same-server' );

		remove_action( 'acrossai_mcp_webmcp_server_changed', $spy, 10 );

		$this->assertSame( 0, $fired, 'Re-saving the same server is not a change and must stay quiet.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	private function seed_server( string $slug, int $is_enabled ): void {
		MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Test ' . $slug,
				'server_slug'            => $slug,
				'description'            => 'Seeded by WebMcpControllerTest',
				'is_enabled'             => $is_enabled,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
	}

	private function truncate_servers(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}
}
