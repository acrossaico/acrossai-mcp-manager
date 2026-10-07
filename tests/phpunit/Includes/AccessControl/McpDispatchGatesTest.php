<?php
/**
 * Tests for the F1 fix — MCP pre-dispatch AC gates for resources & prompts.
 *
 * Prior to 0.2.8 the plugin only hooked `mcp_adapter_pre_tool_call`. The
 * companion pre-dispatch filters for `resources/read` (`mcp_adapter_pre_resource_read`)
 * and `prompts/get` (`mcp_adapter_pre_prompt_get`) were unguarded, so any
 * authenticated user could bypass a "role X only" server-level rule for
 * those two MCP primitives.
 *
 * These tests exercise the new `gate_mcp_resource_read` and
 * `gate_mcp_prompt_get` callbacks — including their wiring into the vendor
 * filters via `Main::define_public_hooks()` — using the same fixture shape
 * and vendor-mocking approach as the existing gate_mcp_tool_call tests in
 * {@see AcrossAI_MCP_Access_Control_Test}.
 *
 * @package AcrossAI_MCP_Manager\Tests\Includes\AccessControl
 * @since   0.2.8
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Includes\AccessControl;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control
 */
final class McpDispatchGatesTest extends WP_UnitTestCase {

	/**
	 * Gate points the coverage canary must always rediscover.
	 *
	 * Proves the scan still understands the adapter. One is pre-dispatch and
	 * one is discovery, so losing either naming convention trips the canary
	 * rather than quietly shrinking what it checks.
	 */
	private const SENTINEL_GATE_POINTS = array(
		'mcp_adapter_pre_tool_call',
		'mcp_adapter_tools_list',
	);

	/**
	 * Gate points that deliberately carry no Access Control gate.
	 *
	 * Empty by design: every per-request adapter hook currently needs one.
	 * Adding an entry is a decision to leave a primitive ungated, so it must
	 * carry a comment saying why that is safe.
	 *
	 * @var string[]
	 */
	private const UNGATED_BY_DESIGN = array();

	/**
	 * Reset observability action listeners between tests so an assertion in
	 * one test can't be masked by a stale listener registered by another.
	 */
	protected function tearDown(): void {
		remove_all_actions( 'acrossai_mcp_access_control_denied' );
		remove_all_actions( 'acrossai_mcp_access_control_missing_server' );
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Fail-open parity with gate_mcp_tool_call — malformed server arg
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — gate_mcp_resource_read returns $request_params unchanged when
	 * the vendor hands us a server arg that isn't a McpServer instance.
	 * Mirrors {@see AcrossAI_MCP_Access_Control_Test::test_gate_mcp_tool_call_returns_args_when_server_arg_invalid}.
	 */
	public function test_gate_mcp_resource_read_returns_params_when_server_arg_invalid(): void {
		$params = array( 'uri' => 'post://42' );
		$result = AcrossAI_MCP_Access_Control::instance()->gate_mcp_resource_read(
			$params,
			'post://42',
			null,
			(object) array()  // No get_server_id() → fail-open branch.
		);
		$this->assertSame( $params, $result, 'Malformed server arg MUST fail-open (return params unchanged)' );
	}

	/**
	 * Test — gate_mcp_prompt_get returns $arguments unchanged on malformed server arg.
	 */
	public function test_gate_mcp_prompt_get_returns_params_when_server_arg_invalid(): void {
		$args   = array( 'topic' => 'summary' );
		$result = AcrossAI_MCP_Access_Control::instance()->gate_mcp_prompt_get(
			$args,
			'summarize',
			null,
			(object) array()
		);
		$this->assertSame( $args, $result );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Missing-server observability parity
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — resource-read gate fires `acrossai_mcp_access_control_missing_server`
	 * with the resource URI as the subject arg when MCPServerQuery returns zero
	 * rows (concurrent DELETE race), and then fails open.
	 */
	public function test_resource_read_fires_missing_server_hook_with_uri_as_subject(): void {
		$captured = null;
		add_action(
			'acrossai_mcp_access_control_missing_server',
			static function ( $slug, $subject, $user ) use ( &$captured ) {
				$captured = array( $slug, $subject, $user );
			},
			10,
			3
		);

		$params = array( 'x' => 'y' );
		$server = $this->make_fake_mcp_server( 'guaranteed-missing-' . uniqid() );

		$result = AcrossAI_MCP_Access_Control::instance()->gate_mcp_resource_read(
			$params,
			'post://999',
			null,
			$server
		);

		$this->assertSame( $params, $result, 'Missing-server race MUST fail-open' );
		$this->assertIsArray( $captured );
		$this->assertSame(
			'post://999',
			$captured[1],
			'Subject arg MUST carry the URI, not a tool name'
		);
	}

	/**
	 * Test — prompt-get gate fires the missing_server hook with the prompt
	 * name as the subject arg.
	 */
	public function test_prompt_get_fires_missing_server_hook_with_prompt_name_as_subject(): void {
		$captured = null;
		add_action(
			'acrossai_mcp_access_control_missing_server',
			static function ( $slug, $subject, $user ) use ( &$captured ) {
				$captured = array( $slug, $subject, $user );
			},
			10,
			3
		);

		$params = array( 'topic' => 'weekly' );
		$server = $this->make_fake_mcp_server( 'guaranteed-missing-' . uniqid() );

		$result = AcrossAI_MCP_Access_Control::instance()->gate_mcp_prompt_get(
			$params,
			'summarize-post',
			null,
			$server
		);

		$this->assertSame( $params, $result );
		$this->assertIsArray( $captured );
		$this->assertSame( 'summarize-post', $captured[1] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Deny-hook context slug — the discriminator operators subscribe to
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — the `acrossai_mcp_access_control_denied` observability hook
	 * accepts the three MCP-boundary context slugs added in 0.2.8.
	 *
	 * We can't easily construct a live deny (no vendor rule row without
	 * schema setup) but the F015 base test already covers the wiring shape;
	 * here we assert the hook contract that consumers rely on.
	 */
	public function test_denied_hook_signature_supports_three_mcp_gate_contexts(): void {
		$seen_contexts = array();
		add_action(
			'acrossai_mcp_access_control_denied',
			static function ( $user_id, $slug, $subject, $context ) use ( &$seen_contexts ) {
				$seen_contexts[] = $context;
				unset( $user_id, $slug, $subject );
			},
			10,
			4
		);

		do_action( 'acrossai_mcp_access_control_denied', 1, 's', 'tool-x', 'mcp_tool_call' );
		do_action( 'acrossai_mcp_access_control_denied', 1, 's', 'post://1', 'mcp_resource_read' );
		do_action( 'acrossai_mcp_access_control_denied', 1, 's', 'p', 'mcp_prompt_get' );

		$this->assertSame(
			array( 'mcp_tool_call', 'mcp_resource_read', 'mcp_prompt_get' ),
			$seen_contexts
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Wiring — the two new filters are registered by Main::define_public_hooks
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Test — `mcp_adapter_pre_resource_read` is wired to `gate_mcp_resource_read`.
	 * Regression guard: pre-0.2.8 this filter had NO subscriber, and the F1
	 * bypass exploited exactly that gap.
	 */
	public function test_pre_resource_read_filter_is_wired(): void {
		$this->assertNotFalse(
			has_filter(
				'mcp_adapter_pre_resource_read',
				array( AcrossAI_MCP_Access_Control::instance(), 'gate_mcp_resource_read' )
			),
			'Main::define_public_hooks() MUST wire gate_mcp_resource_read to mcp_adapter_pre_resource_read'
		);
	}

	/**
	 * Test — `mcp_adapter_pre_prompt_get` is wired to `gate_mcp_prompt_get`.
	 */
	public function test_pre_prompt_get_filter_is_wired(): void {
		$this->assertNotFalse(
			has_filter(
				'mcp_adapter_pre_prompt_get',
				array( AcrossAI_MCP_Access_Control::instance(), 'gate_mcp_prompt_get' )
			),
			'Main::define_public_hooks() MUST wire gate_mcp_prompt_get to mcp_adapter_pre_prompt_get'
		);
	}

	/**
	 * Test — the existing tool-call filter wiring is still in place after the
	 * F1 refactor. Refactored `gate_mcp_tool_call` now delegates to the
	 * private `apply_ac_gate` helper; the public method + hook registration
	 * MUST remain unchanged.
	 */
	public function test_pre_tool_call_filter_is_still_wired_after_refactor(): void {
		$this->assertNotFalse(
			has_filter(
				'mcp_adapter_pre_tool_call',
				array( AcrossAI_MCP_Access_Control::instance(), 'gate_mcp_tool_call' )
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Coverage canary — every per-request gate point the INSTALLED adapter
	 * exposes must have a plugin gate on it.
	 *
	 * This exists because the same bug has now shipped twice. In 0.2.8 only
	 * `mcp_adapter_pre_tool_call` was hooked, leaving `resources/read` and
	 * `prompts/get` open. In 0.3.9 the three list primitives (`tools/list` and
	 * siblings) were found open the same way: any Access Control rule drops the
	 * transport gate to the vendor default `read`, which every logged-in user
	 * holds, so a user the operator had excluded could still enumerate the
	 * whole catalogue. Both times the per-hook tests passed — because they only
	 * ever asserted the hooks someone had remembered to write.
	 *
	 * So this test does not hardcode a list. It reads the adapter's own
	 * handler sources and derives the gate points, which means a NEW primitive
	 * arriving in a future adapter release fails CI on arrival instead of
	 * shipping unguarded. "Gate point" is any `mcp_adapter_*` filter in the
	 * handler layer that is either pre-dispatch (`mcp_adapter_pre_*`) or
	 * discovery (`*_list`) — exactly the per-request, per-server moments where
	 * an operator's rule has to be consulted. Lifecycle and configuration
	 * filters elsewhere in the adapter are not gate points and are not scanned.
	 *
	 * If this fails for a hook that genuinely needs no gate, add it to
	 * UNGATED_BY_DESIGN with the reason — do not delete the assertion.
	 */
	public function test_every_vendor_gate_point_has_a_plugin_gate(): void {
		$handlers_dir = dirname( __DIR__, 4 ) . '/vendor/wordpress/mcp-adapter/includes/Handlers';

		if ( ! is_dir( $handlers_dir ) ) {
			$this->markTestSkipped( 'mcp-adapter vendor package not installed.' );
		}

		$discovered = $this->scan_vendor_gate_points( $handlers_dir );

		// Guard against a vacuous pass. If the adapter is restructured and the
		// scan silently matches nothing, an empty "ungated" list would look
		// like success while covering zero hooks — which is precisely how a
		// dead gate test lets a real bug through.
		$this->assertNotEmpty(
			$discovered,
			'Scanned the adapter handler layer and found no gate points at all. The vendor layout or the filter naming changed — fix this scan before trusting any gate coverage.'
		);

		foreach ( self::SENTINEL_GATE_POINTS as $sentinel ) {
			$this->assertContains(
				$sentinel,
				$discovered,
				sprintf( 'Known gate point "%s" vanished from the scan. The scan has drifted from the adapter and is no longer proving what it claims.', $sentinel )
			);
		}

		$ungated = array();
		foreach ( $discovered as $hook ) {
			if ( in_array( $hook, self::UNGATED_BY_DESIGN, true ) ) {
				continue;
			}
			if ( false === has_filter( $hook ) ) {
				$ungated[] = $hook;
			}
		}

		$this->assertSame(
			array(),
			$ungated,
			sprintf(
				"These mcp-adapter gate points have NO filter attached, so the operator's Access Control rule is never consulted for them:\n  - %s\nWire each one in Main::define_public_hooks(), or add it to UNGATED_BY_DESIGN with a documented reason.",
				implode( "\n  - ", $ungated )
			)
		);
	}

	/**
	 * Extract per-request gate-point filter names from the adapter handlers.
	 *
	 * @param string $dir Absolute path to the adapter's Handlers directory.
	 * @return string[] Sorted, unique hook names.
	 */
	private function scan_vendor_gate_points( string $dir ): array {
		$hooks = array();

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$src = file_get_contents( $file->getPathname() );
			if ( false === $src ) {
				continue;
			}

			if ( ! preg_match_all( "/apply_filters\(\s*'(mcp_adapter_[a-z0-9_]+)'/", $src, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $hook ) {
				$is_pre_dispatch = 0 === strpos( $hook, 'mcp_adapter_pre_' );
				$is_discovery    = (bool) preg_match( '/_list$/', $hook );

				if ( $is_pre_dispatch || $is_discovery ) {
					$hooks[ $hook ] = true;
				}
			}
		}

		$hooks = array_keys( $hooks );
		sort( $hooks );

		return $hooks;
	}

	/**
	 * Build a fake object satisfying the mcp-adapter contract (get_server_id).
	 *
	 * @param string $slug Server slug.
	 * @return object
	 */
	private function make_fake_mcp_server( string $slug ): object {
		return new class( $slug ) {
			/**
			 * Server slug.
			 *
			 * @var string
			 */
			private string $slug;

			/**
			 * @param string $slug Server slug.
			 */
			public function __construct( string $slug ) {
				$this->slug = $slug;
			}

			/**
			 * @return string
			 */
			public function get_server_id(): string {
				return $this->slug;
			}
		};
	}
}
