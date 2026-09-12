<?php
/**
 * Tests for TransportPermissionDefault (Feature 042).
 *
 * The class hooks vendor `mcp_adapter_default_transport_permission_user_capability`
 * with a rule-aware callback: return `manage_options` when the current
 * server has no wpb-ac rule, otherwise return the vendor default so
 * `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` (F015, unchanged)
 * enforces the operator's rule at `mcp_adapter_pre_tool_call`.
 *
 * The 7-branch early-return chain in `filter_default_capability()` is
 * covered here — one test per branch — plus singleton, memoization,
 * multi-server independence, and default-passthrough contracts.
 *
 * Fixture strategy:
 *   - `WP_UnitTestCase` gives transactional DB rollback per test, so
 *     server rows + rule rows inserted here don't leak between tests.
 *   - Singleton `$memo` is reset via reflection in setUp so per-request
 *     caching from one test never contaminates the next.
 *   - No vendor mocking: uses real `RuleQuery` + real MCPServerQuery.
 *
 * @package AcrossAI_MCP_Manager\Tests\Includes\AccessControl
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Includes\AccessControl;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP_REST_Request;
use WP_UnitTestCase;
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;

/**
 * @coversDefaultClass \AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault
 */
final class TransportPermissionDefaultTest extends WP_UnitTestCase {

	private const NAMESPACE_SLUG = 'acrossai-mcp-manager';

	/**
	 * Reset the singleton's per-request memo before each test so cached
	 * results from a prior test cannot mask real filter behavior.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_memo();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Singleton contract
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — instance() returns the SAME object on repeat calls (singleton).
	 */
	public function test_instance_returns_same_singleton_object(): void {
		$a = TransportPermissionDefault::instance();
		$b = TransportPermissionDefault::instance();
		$this->assertSame( $a, $b, 'instance() MUST return the same singleton object' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Route-parsing early returns (branches 1-4 of the 7-branch chain)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — empty route → default is returned unchanged.
	 */
	public function test_empty_route_returns_default_unchanged(): void {
		$ctx    = $this->build_context( '' );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'read', $ctx );
		$this->assertSame( 'read', $result, 'Empty route MUST short-circuit to the vendor default' );
	}

	/**
	 * Test — route with only one segment (no namespace/route split) → default.
	 */
	public function test_single_segment_route_returns_default(): void {
		$ctx    = $this->build_context( '/lonely' );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'read', $ctx );
		$this->assertSame( 'read', $result, 'Route without namespace/route split MUST return default' );
	}

	/**
	 * Test — route with empty namespace segment (`//foo`) → default.
	 */
	public function test_empty_namespace_segment_returns_default(): void {
		$ctx    = $this->build_context( '//some-route' );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'read', $ctx );
		$this->assertSame( 'read', $result, 'Empty namespace segment MUST return default' );
	}

	/**
	 * Test — route with empty path segment (`/mcp/`) → default.
	 */
	public function test_empty_path_segment_returns_default(): void {
		$ctx    = $this->build_context( '/mcp/' );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'read', $ctx );
		$this->assertSame( 'read', $result, 'Empty path segment MUST return default' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Server-lookup branch (branch 3 of the chain)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — well-formed route but no MCPServer row matches → default.
	 *
	 * Uses a namespace/route pair that cannot exist in the plugin's DB
	 * (uniqued via uniqid to guarantee no collision with real rows).
	 */
	public function test_unknown_route_returns_default_when_no_server_matches(): void {
		$slug   = 'nonexistent-' . uniqid();
		$ctx    = $this->build_context( '/mcp/' . $slug );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'read', $ctx );
		$this->assertSame(
			'read',
			$result,
			'Route not owned by any plugin server row MUST return default (route belongs to some other consumer of the filter)'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Core behavior — the rule-aware branches (5, 6, 7 of the chain)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — server exists AND has NO rule → returns `manage_options`.
	 *
	 * This is the P1 headline behavior of the feature.
	 */
	public function test_returns_manage_options_when_server_exists_with_no_rule(): void {
		$slug = $this->make_server( 'test-no-rule-' . uniqid() );
		$this->purge_rule_for( $slug );

		$ctx    = $this->build_context( '/mcp/' . $slug );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'read', $ctx );

		$this->assertSame(
			'manage_options',
			$result,
			'Server with no wpb-ac rule MUST default to manage_options (admin-only)'
		);
	}

	/**
	 * Test — server exists AND has a rule set → returns the vendor default.
	 *
	 * The whole point of returning the vendor default here is to defer to
	 * `gate_mcp_tool_call` at `mcp_adapter_pre_tool_call` — which enforces
	 * the actual rule against the requesting user.
	 */
	public function test_returns_default_when_server_has_any_rule(): void {
		$slug = $this->make_server( 'test-with-rule-' . uniqid() );
		$this->set_rule_for( $slug, 'wp_role', array( 'editor' ) );

		$ctx    = $this->build_context( '/mcp/' . $slug );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'read', $ctx );

		$this->assertSame(
			'read',
			$result,
			'Server with any wpb-ac rule MUST return vendor default so gate_mcp_tool_call handles enforcement'
		);
	}

	/**
	 * Test — the DEFAULT SEEDED server (mcp-adapter-default-server, activated
	 * by the plugin bootstrap) gets admin-only when it has no rule.
	 *
	 * This is the "plugin activates fresh → admin-only out of the box" case.
	 */
	public function test_returns_manage_options_for_seeded_default_server(): void {
		// Establish both preconditions explicitly rather than inheriting them
		// from bootstrap state. Other test classes TRUNCATE this table, and
		// TRUNCATE implicitly COMMITs in MySQL — escaping WP_UnitTestCase's
		// per-test rollback — so the row Activator::activate() seeded at
		// bootstrap may already be gone by the time this runs.
		DefaultServerSeeder::seed();
		$this->purge_rule_for( 'mcp-adapter-default-server' );

		// Assert the preconditions so a future failure names its own cause:
		// filter_default_capability() returns the vendor default both when the
		// route has no matching row AND when a rule exists, and those are very
		// different problems.
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_route_namespace' => 'mcp',
				'server_route'           => 'mcp-adapter-default-server',
				'number'                 => 1,
			)
		);
		$this->assertNotEmpty(
			$rows,
			'Precondition: the seeded default server row must exist for this assertion to mean anything.'
		);

		if ( class_exists( RuleQuery::class ) ) {
			$rule = ( new RuleQuery( AcrossAI_MCP_Access_Control::TABLE_SLUG ) )
				->get_rule( self::NAMESPACE_SLUG, 'mcp-adapter-default-server' );
			$this->assertEmpty(
				$rule['key'] ?? '',
				'Precondition: no wpb-ac rule may be configured for the seeded default server.'
			);
		}

		$ctx    = $this->build_context( '/mcp/mcp-adapter-default-server' );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'read', $ctx );

		$this->assertSame(
			'manage_options',
			$result,
			'The plugin-seeded default server MUST default to admin-only when no rule is configured'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Default-passthrough contract
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — when the callback returns the "default" branch, it returns
	 * whatever string the caller passed as $default_capability — NOT a
	 * hardcoded 'read'. Third-party plugins that hook the same filter at
	 * an earlier priority and mutate the default must have their value
	 * respected on the deferral branches.
	 */
	public function test_defers_to_arbitrary_default_string_when_no_rule_lookup_needed(): void {
		$ctx    = $this->build_context( '/mcp/nonexistent-passthrough-' . uniqid() );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'edit_posts', $ctx );
		$this->assertSame(
			'edit_posts',
			$result,
			'Deferral branches MUST return the passed-in $default_capability unchanged (not a hardcoded string)'
		);
	}

	/**
	 * Test — when a rule EXISTS, the callback also returns the passed-in
	 * $default_capability, not a hardcoded 'read'.
	 */
	public function test_defers_to_arbitrary_default_string_when_rule_exists(): void {
		$slug = $this->make_server( 'test-passthrough-rule-' . uniqid() );
		$this->set_rule_for( $slug, 'wp_role', array( 'author' ) );

		$ctx    = $this->build_context( '/mcp/' . $slug );
		$result = TransportPermissionDefault::instance()->filter_default_capability( 'contribute', $ctx );

		$this->assertSame(
			'contribute',
			$result,
			'Rule-exists branch MUST return the passed-in $default_capability unchanged'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Memoization
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — repeated calls with the same route return the MEMOIZED result,
	 * even if the underlying DB state changes between calls.
	 *
	 * Setup: server with no rule → call returns manage_options + memoizes.
	 * Then insert a rule for the same slug (fresh call would now return
	 * the deferral default). Call again → still returns manage_options
	 * from memo.
	 */
	public function test_repeated_calls_hit_memo_and_do_not_re_query_db(): void {
		$slug = $this->make_server( 'test-memo-' . uniqid() );
		$this->purge_rule_for( $slug );

		$instance = TransportPermissionDefault::instance();
		$ctx      = $this->build_context( '/mcp/' . $slug );

		$first = $instance->filter_default_capability( 'read', $ctx );
		$this->assertSame( 'manage_options', $first );

		// Mutate underlying state — a fresh call WOULD now return 'read'.
		$this->set_rule_for( $slug, 'wp_role', array( 'editor' ) );

		$second = $instance->filter_default_capability( 'read', $ctx );
		$this->assertSame(
			'manage_options',
			$second,
			'Second call with same route MUST return memoized result, ignoring the mid-request rule insertion'
		);

		// Verify the memo array actually has the key.
		$memo = $this->read_memo();
		$this->assertArrayHasKey( 'mcp/' . $slug, $memo, 'Memo MUST contain an entry for the resolved route key' );
	}

	/**
	 * Test — memo keys are scoped per (namespace, route) — two different
	 * servers cached side-by-side do not collide.
	 */
	public function test_memo_keys_are_per_route_and_do_not_collide(): void {
		$slug_a = $this->make_server( 'memo-a-' . uniqid() );
		$slug_b = $this->make_server( 'memo-b-' . uniqid() );
		$this->purge_rule_for( $slug_a );
		$this->set_rule_for( $slug_b, 'wp_role', array( 'editor' ) );

		$instance = TransportPermissionDefault::instance();

		$result_a = $instance->filter_default_capability( 'read', $this->build_context( '/mcp/' . $slug_a ) );
		$result_b = $instance->filter_default_capability( 'read', $this->build_context( '/mcp/' . $slug_b ) );

		$this->assertSame( 'manage_options', $result_a, 'Server A (no rule) MUST return manage_options' );
		$this->assertSame( 'read', $result_b, 'Server B (with rule) MUST return default — independently memoized' );

		$memo = $this->read_memo();
		$this->assertArrayHasKey( 'mcp/' . $slug_a, $memo );
		$this->assertArrayHasKey( 'mcp/' . $slug_b, $memo );
		$this->assertSame( 'manage_options', $memo[ 'mcp/' . $slug_a ] );
		$this->assertSame( 'read', $memo[ 'mcp/' . $slug_b ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Filter registration integration — Main::define_public_hooks
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — the class is actually wired to the vendor filter by Main.
	 *
	 * Calls `apply_filters` on the real vendor filter name with a fake
	 * context; asserts the result reflects our rule-aware callback (not
	 * the raw vendor default). Regression guard against B43-style
	 * mis-wiring (silent Loader signature mismatch).
	 */
	public function test_filter_is_wired_and_intercepts_vendor_default(): void {
		$slug = $this->make_server( 'wired-check-' . uniqid() );
		$this->purge_rule_for( $slug );

		$ctx    = $this->build_context( '/mcp/' . $slug );
		$result = apply_filters( 'mcp_adapter_default_transport_permission_user_capability', 'read', $ctx );

		$this->assertSame(
			'manage_options',
			$result,
			'apply_filters MUST route through TransportPermissionDefault (Main::define_public_hooks wiring is intact)'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Fixture helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Build an HttpRequestContext from a route path.
	 *
	 * WP_REST_Request stores the route via its constructor's second arg;
	 * `get_route()` returns it verbatim (with leading slash if present).
	 */
	private function build_context( string $route ): HttpRequestContext {
		$request = new WP_REST_Request( 'POST', $route );
		return new HttpRequestContext( $request );
	}

	/**
	 * Insert a fresh MCPServer row and return its slug. The row is
	 * transactional — rolled back at test teardown.
	 */
	private function make_server( string $slug ): string {
		$id = MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Test Server ' . $slug,
				'server_slug'            => $slug,
				'description'            => 'Fixture server for TransportPermissionDefault tests.',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);

		$this->assertNotFalse( $id, 'Fixture server insertion MUST succeed' );
		return $slug;
	}

	/**
	 * Insert a rule for a server via the vendor RuleQuery API.
	 *
	 * @param string[] $values Provider values (role slugs, cap slugs, etc.).
	 */
	private function set_rule_for( string $server_slug, string $ac_key, array $values ): void {
		if ( ! class_exists( RuleQuery::class ) ) {
			$this->markTestSkipped( 'wpb-access-control vendor package not available in this test env' );
		}
		$rules = new RuleQuery( AcrossAI_MCP_Access_Control::TABLE_SLUG );
		$rules->set_rule( self::NAMESPACE_SLUG, $server_slug, $ac_key, $values );
	}

	/**
	 * Explicitly clear any rule for a server. Defensive helper for tests
	 * that assert "no rule" behavior — the seeded default server or any
	 * pre-existing row could otherwise carry a rule from another test.
	 */
	private function purge_rule_for( string $server_slug ): void {
		if ( ! class_exists( RuleQuery::class ) ) {
			return;
		}
		$rules = new RuleQuery( AcrossAI_MCP_Access_Control::TABLE_SLUG );
		$rules->clear_rule( self::NAMESPACE_SLUG, $server_slug );
	}

	/**
	 * Reset the singleton's private $memo array to empty via reflection.
	 * Called from setUp so cached decisions from a prior test never mask
	 * real filter behavior in the next.
	 */
	private function reset_memo(): void {
		$instance   = TransportPermissionDefault::instance();
		$reflection = new \ReflectionClass( $instance );
		$prop       = $reflection->getProperty( 'memo' );
		$prop->setAccessible( true );
		$prop->setValue( $instance, array() );
	}

	/**
	 * Read the singleton's private $memo array for assertion.
	 *
	 * @return array<string, string>
	 */
	private function read_memo(): array {
		$instance   = TransportPermissionDefault::instance();
		$reflection = new \ReflectionClass( $instance );
		$prop       = $reflection->getProperty( 'memo' );
		$prop->setAccessible( true );
		/** @var array<string, string> $memo */
		$memo = $prop->getValue( $instance );
		return $memo;
	}
}
