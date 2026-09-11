<?php
/**
 * Feature 017 — AbilityExposureGate SEC-001 regression test.
 *
 * The gate is the closure for the SEC-001 HIGH plan-review finding — it
 * MUST return `WP_Error` 403 when a per-server override says `is_exposed=0`
 * for the tool being invoked, MUST propagate an earlier-priority WP_Error
 * unchanged (never override an F015 deny), and MUST fail-open on
 * unresolvable server context (matches D19 pattern).
 *
 * 0.1.1 adds the sanitized-name regression. The gate resolved its ability with
 * `wp_get_ability( $tool_name )`, but `$tool_name` at `mcp_adapter_pre_tool_call`
 * is the vendor-SANITIZED tool name (`McpNameSanitizer::sanitize_name()` swaps
 * `/` → `-` at registration), while the Abilities registry is an exact-key
 * lookup on the raw slashed slug. Every bundled ability slug contains a slash,
 * so the lookup missed on all of them: the gate fail-opened on every real call
 * and emitted a `_doing_it_wrong()` notice each time. The pre-existing cases
 * below passed only because they invoke the gate with the RAW slug, which the
 * sanitizer never sees. Same defect ToolExposureGate carried; same resolution
 * order used here, plus the tool-level exemption that keeps "Disable All" from
 * 403-ing the protocol tools and breaking every connected client.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\MCP
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
use AcrossAI_MCP_Manager\Includes\MCP\AbilityExposureGate;
use WP_Error;
use WP_UnitTestCase;

/**
 * Minimal stand-in for the vendor McpServer object — the gate only calls
 * `$server->get_server_id()`, which returns the F011 `server_slug`.
 */
final class FakeMcpServer {
	private string $slug;
	public function __construct( string $slug ) {
		$this->slug = $slug;
	}
	public function get_server_id(): string {
		return $this->slug;
	}
}

/**
 * Duck-typed ability-backed McpTool — reports its source slug the way the
 * vendor's RegisterAbilityAsMcpTool does. The only thing that can resolve a
 * tool renamed by `mcp_adapter_tool_name`.
 */
final class FakeAbilityBackedToolForExposureGate {
	private string $ability_name;
	public function __construct( string $ability_name ) {
		$this->ability_name = $ability_name;
	}
	/**
	 * @return array<string, string>
	 */
	public function get_observability_context(): array {
		return array( 'ability_name' => $this->ability_name );
	}
}

/**
 * @coversNothing
 */
class AbilityExposureGateTest extends WP_UnitTestCase {

	private int $server_id      = 0;
	private string $server_slug = '';

	public function set_up(): void {
		parent::set_up();
		MCPServerTable::instance()->maybe_upgrade();
		MCPServerAbilityTable::instance()->maybe_upgrade();
		DefaultServerSeeder::seed();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_server_abilities' ) );

		$row = $wpdb->get_row( "SELECT id, server_slug FROM {$wpdb->prefix}acrossai_mcp_servers LIMIT 1" ); // phpcs:ignore
		if ( $row ) {
			$this->server_id   = (int) $row->id;
			$this->server_slug = (string) $row->server_slug;
		}
		ExposureResolver::_reset_cache_for_tests();
	}

	public function test_returns_args_unchanged_when_exposure_is_true(): void {
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', true );
		$gate = AbilityExposureGate::instance();
		$args = array( 'foo' => 'bar' );
		$out  = $gate->gate_tool_call_by_exposure( $args, 'core/get-user-info', null, new FakeMcpServer( $this->server_slug ) );
		$this->assertSame( $args, $out );
	}

	public function test_returns_403_wp_error_when_exposure_is_false(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'wp_get_ability() is not available in this test environment.' );
		}
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', false );
		$gate = AbilityExposureGate::instance();
		$out  = $gate->gate_tool_call_by_exposure( array( 'x' => 1 ), 'core/get-user-info', null, new FakeMcpServer( $this->server_slug ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'acrossai_mcp_ability_not_exposed', $out->get_error_code() );
		$data = $out->get_error_data();
		$this->assertSame( 403, isset( $data['status'] ) ? (int) $data['status'] : 0 );
	}

	public function test_propagates_existing_wp_error_unchanged(): void {
		// Simulate an F015 deny already-returned by an earlier priority-10
		// callback. F017 MUST return it unchanged — never override a deny.
		$incoming = new WP_Error( 'acrossai_mcp_access_denied', 'blocked by F015', array( 'status' => 403 ) );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', true );
		$gate = AbilityExposureGate::instance();
		$out  = $gate->gate_tool_call_by_exposure( $incoming, 'core/get-user-info', null, new FakeMcpServer( $this->server_slug ) );
		$this->assertSame( $incoming, $out, 'F017 must never override an F015 deny.' );
	}

	public function test_fails_open_when_server_slug_unresolvable(): void {
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', false );
		$gate = AbilityExposureGate::instance();
		$args = array( 'foo' => 'bar' );
		$out  = $gate->gate_tool_call_by_exposure( $args, 'core/get-user-info', null, new FakeMcpServer( 'no-such-server' ) );
		$this->assertSame( $args, $out, 'Missing server slug → fail-open per D19.' );
	}

	public function test_fails_open_when_server_object_lacks_get_server_id(): void {
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', false );
		$gate = AbilityExposureGate::instance();
		$args = array( 'foo' => 'bar' );
		$out  = $gate->gate_tool_call_by_exposure( $args, 'core/get-user-info', null, new \stdClass() );
		$this->assertSame( $args, $out, 'Unrecognized server object → fail-open per D19.' );
	}

	// -----------------------------------------------------------------
	// 0.1.1 — sanitized-name resolution.
	// -----------------------------------------------------------------

	public function tear_down(): void {
		remove_all_filters( 'acrossai_mcp_manager_tool_abilities' );
		parent::tear_down();
	}

	private function maybe_skip(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test environment.' );
		}
	}

	/**
	 * Register a tool-typed ability under a slashed slug. Re-uses an existing
	 * registration — the vendor registers the protocol tools itself — rather
	 * than colliding with it.
	 */
	private function register_ability( string $name ): void {
		$this->maybe_skip();

		if ( \wp_has_ability( $name ) ) {
			return;
		}

		\wp_register_ability(
			$name,
			array(
				'label'               => $name,
				'description'         => 'Fixture ' . $name,
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback'    => static fn () => array(),
				'permission_callback' => static fn () => true,
				'meta'                => array(
					'mcp' => array(
						'public' => false,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * @param mixed $mcp_tool Vendor McpTool stand-in, or null.
	 * @return array<mixed>|WP_Error
	 */
	private function gate( string $tool_name, $mcp_tool = null ) {
		return AbilityExposureGate::instance()->gate_tool_call_by_exposure(
			array( 'p' => 'q' ),
			$tool_name,
			$mcp_tool,
			new FakeMcpServer( $this->server_slug )
		);
	}

	/**
	 * The regression itself: the form an AI client actually sends.
	 */
	public function test_hidden_ability_is_denied_under_its_sanitized_tool_name(): void {
		$this->register_ability( 'gate-fixture/hidden-one' );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'gate-fixture/hidden-one', false );
		ExposureResolver::_reset_cache_for_tests();

		$out = $this->gate( 'gate-fixture-hidden-one' );

		$this->assertInstanceOf( WP_Error::class, $out, 'Sanitized tool names must still resolve to their ability.' );
		$this->assertSame( 'acrossai_mcp_ability_not_exposed', $out->get_error_code() );
	}

	public function test_exposed_ability_passes_under_its_sanitized_tool_name(): void {
		$this->register_ability( 'gate-fixture/shown-one' );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'gate-fixture/shown-one', true );
		ExposureResolver::_reset_cache_for_tests();

		$this->assertSame( array( 'p' => 'q' ), $this->gate( 'gate-fixture-shown-one' ) );
	}

	/**
	 * An `mcp_adapter_tool_name` rename defeats every lookup — only the tool's
	 * own report can resolve it.
	 */
	public function test_renamed_tool_resolves_through_its_reported_ability(): void {
		$this->register_ability( 'gate-fixture/renamed-source' );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'gate-fixture/renamed-source', false );
		ExposureResolver::_reset_cache_for_tests();

		$out = $this->gate(
			'totally-renamed-by-a-filter',
			new FakeAbilityBackedToolForExposureGate( 'gate-fixture/renamed-source' )
		);

		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_unregistered_tool_name_still_fails_open(): void {
		$this->maybe_skip();

		$this->assertSame( array( 'p' => 'q' ), $this->gate( 'nothing-registered-under-this' ) );
	}

	// -----------------------------------------------------------------
	// Tool-level exemption — "Disable All" must not break the protocol.
	// -----------------------------------------------------------------

	public function test_protocol_tools_are_never_denied(): void {
		$this->register_ability( 'mcp-adapter/execute-ability' );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'mcp-adapter/execute-ability', false );
		ExposureResolver::_reset_cache_for_tests();

		$this->assertSame(
			array( 'p' => 'q' ),
			$this->gate( 'mcp-adapter-execute-ability' ),
			'Hiding every ability must not 403 the tools clients bootstrap with.'
		);
	}

	public function test_a_declared_dispatcher_is_exempt_too(): void {
		$this->register_ability( 'toolset/cron' );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'toolset/cron', false );
		ExposureResolver::_reset_cache_for_tests();
		add_filter(
			'acrossai_mcp_manager_tool_abilities',
			static function ( array $slugs ): array {
				$slugs[] = 'toolset/cron';
				return $slugs;
			}
		);

		$this->assertSame( array( 'p' => 'q' ), $this->gate( 'toolset-cron' ) );
	}

	/**
	 * The exemption list subtracts as well as adds.
	 */
	public function test_removing_a_default_from_the_filter_un_exempts_it(): void {
		$this->register_ability( 'mcp-adapter/get-ability-info' );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'mcp-adapter/get-ability-info', false );
		ExposureResolver::_reset_cache_for_tests();
		add_filter(
			'acrossai_mcp_manager_tool_abilities',
			static function ( array $slugs ): array {
				return array_values( array_diff( $slugs, array( 'mcp-adapter/get-ability-info' ) ) );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $this->gate( 'mcp-adapter-get-ability-info' ) );
	}
}
