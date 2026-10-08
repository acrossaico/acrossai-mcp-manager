<?php
/**
 * F093 — WebMcpContext.
 *
 * This file is mostly a CONTRACT test, and the contract is unusual enough to
 * be worth stating plainly.
 *
 * `WebMcpContext` deliberately does not implement any access or exposure
 * check. It establishes the context those checks already read, then replays
 * the vendor filters so the four already-wired gates fire themselves. That
 * buys us one copy of the security logic instead of two — but it moves the
 * failure mode somewhere a normal test would never look:
 *
 *   - If a vendor upgrade RENAMES `mcp_adapter_pre_tool_call`, the replay
 *     fires a filter nobody listens to. Every gate "passes".
 *   - If a vendor upgrade CHANGES THE ARGUMENT COUNT, the gates still run but
 *     receive `null` where `$server` should be. `apply_ac_gate()` and
 *     `gate_tool_call_by_curation()` both bail to allow on a non-object
 *     server, by design, because that is the right behaviour for a malformed
 *     vendor call. So they pass too.
 *
 * Both failures are silent, both un-gate the browser completely, and neither
 * changes a single line of our code. Hence the two assertions below that pin
 * the hook names and their argument counts against the live wiring in
 * `Includes\Main`. They are the reason this class is allowed to exist.
 *
 * The rest of the file covers fail-closed resolution and holder restoration.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\MCP
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\MCP;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\WebMCP\WebMcpContext;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

final class WebMcpContextTest extends WP_UnitTestCase {

	public function tearDown(): void {
		// The holder is a plugin-wide singleton; a leaked server would make
		// the next test in the suite pass or fail for the wrong reason.
		CurrentServerHolder::instance()->set( null );
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// The contract. See the file docblock for why these two matter most.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_the_replayed_filters_actually_have_listeners(): void {
		foreach ( array_keys( WebMcpContext::FILTER_ARITY ) as $hook ) {
			$this->assertNotFalse(
				has_filter( $hook ),
				sprintf(
					'WebMcpContext replays "%s" so the already-wired gates fire. Nothing is listening '
					. 'on it, so the replay is a no-op and every gate silently passes. Either the '
					. 'vendor renamed the hook or Main::define_public_hooks() stopped wiring it.',
					$hook
				)
			);
		}
	}

	public function test_every_listener_accepts_enough_arguments_to_see_the_server(): void {
		global $wp_filter;

		foreach ( WebMcpContext::FILTER_ARITY as $hook => $required ) {
			$this->assertArrayHasKey( $hook, $wp_filter, sprintf( 'No callbacks registered on "%s".', $hook ) );

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$this->assertGreaterThanOrEqual(
						$required,
						(int) $callback['accepted_args'],
						sprintf(
							'A callback on "%s" at priority %d accepts %d args but the gate needs %d. '
							. 'The $server argument arrives last, so a short arity hands the gate null — '
							. 'and both apply_ac_gate() and gate_tool_call_by_curation() bail to ALLOW '
							. 'on a non-object server. The browser would be completely un-gated and '
							. 'nothing else would fail.',
							$hook,
							$priority,
							(int) $callback['accepted_args'],
							$required
						)
					);
				}
			}
		}
	}

	public function test_pre_tool_call_still_carries_all_three_enforcement_layers(): void {
		global $wp_filter;

		$hook = WebMcpContext::FILTER_PRE_TOOL_CALL;
		$this->assertArrayHasKey( $hook, $wp_filter );

		// Access control (10), ability exposure (20), tool curation (30).
		// Losing any one of them is a silent downgrade: the call still
		// succeeds, it is simply no longer checked for that dimension.
		foreach ( array( 10, 20, 30 ) as $priority ) {
			$this->assertArrayHasKey(
				$priority,
				$wp_filter[ $hook ]->callbacks,
				sprintf(
					'Nothing is registered on "%s" at priority %d. That priority carries one of the '
					. 'three call-time gates (10 access control, 20 ability exposure, 30 tool '
					. 'curation); losing it un-gates that dimension for BOTH the remote path and '
					. 'WebMCP, with no other symptom.',
					$hook,
					$priority
				)
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Fail closed.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_an_empty_slug_is_refused(): void {
		$result = WebMcpContext::resolve( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'acrossai_mcp_webmcp_no_server_selected', $result->get_error_code() );
	}

	public function test_an_unregistered_slug_is_refused(): void {
		$result = WebMcpContext::resolve( 'no-such-server-' . wp_generate_uuid4() );

		$this->assertWPError( $result );
		$this->assertSame( 'acrossai_mcp_webmcp_server_not_registered', $result->get_error_code() );
	}

	public function test_with_does_not_run_the_callback_when_the_server_cannot_be_resolved(): void {
		$ran = false;

		$result = WebMcpContext::with(
			'no-such-server-' . wp_generate_uuid4(),
			static function () use ( &$ran ) {
				$ran = true;
				return 'should not happen';
			}
		);

		$this->assertWPError( $result );
		$this->assertFalse(
			$ran,
			'The callback ran despite an unresolvable server. Everything downstream assumes a '
				. 'resolved context; running without one is the fail-open case this class exists '
				. 'to prevent.'
		);
	}

	public function test_a_refusal_leaves_the_holder_untouched(): void {
		$before = CurrentServerHolder::instance()->get();

		WebMcpContext::with( 'no-such-server-' . wp_generate_uuid4(), static fn() => null );

		$this->assertSame(
			$before,
			CurrentServerHolder::instance()->get(),
			'A refused WebMCP request modified the shared holder. In a long-lived PHP process that '
				. 'leaks into the next request.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Hook-name drift. Cheap, and catches a rename the arity test cannot.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_hook_names_match_the_vendor_contract(): void {
		$this->assertSame( 'mcp_adapter_tools_list', WebMcpContext::FILTER_TOOLS_LIST );
		$this->assertSame( 'mcp_adapter_pre_tool_call', WebMcpContext::FILTER_PRE_TOOL_CALL );
		$this->assertSame(
			array(
				'mcp_adapter_tools_list'    => 3,
				'mcp_adapter_pre_tool_call' => 4,
			),
			WebMcpContext::FILTER_ARITY
		);
	}
}
